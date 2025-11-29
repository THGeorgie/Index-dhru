<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'db.php';

/* =========================
   FUNCIONES DHRU
========================= */
function dhru_success($message, $extra = []) {
    echo json_encode([
        "SUCCESS" => [
            array_merge(["MESSAGE" => $message], $extra)
        ],
        "apiversion" => "6.1"
    ]);
    exit;
}

function dhru_error($message) {
    echo json_encode([
        "ERROR" => [
            ["MESSAGE" => $message]
        ],
        "apiversion" => "6.1"
    ]);
    exit;
}

function log_msg($msg){
    file_put_contents("api.log", "[".date("Y-m-d H:i:s")."] ".$msg."\n", FILE_APPEND);
}

/* =========================
   INPUT
========================= */
$username     = $_REQUEST['username'] ?? '';
$apiaccesskey = $_REQUEST['apiaccesskey'] ?? '';
$action       = $_REQUEST['action'] ?? '';
$action = strtolower($action);
log_msg("ACTION RECIBIDA: ".$action);

$parameters   = $_POST['parameters'] ?? '';

// Normalizar action (importante para DHRU)
$action = strtolower($action);

if($parameters){
    $parameters = json_decode(base64_decode($parameters), true);
}

if(empty($username) || empty($apiaccesskey) || empty($action)){
    dhru_error("Missing parameters");
}

/* =========================
   RATE LIMIT
========================= */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = sys_get_temp_dir() . "/api_rate_" . md5($ip);
$requests = @file_get_contents($rate_file);
$requests = $requests ? (int)$requests : 0;

if($requests > 1000){
    dhru_error("Too many requests");
}
file_put_contents($rate_file, $requests + 1);

/* =========================
   AUTH
========================= */
$stmt = $pdo->prepare("SELECT * FROM api_users 
                       WHERE api_user=? AND api_key=? AND status='active'");
$stmt->execute([$username, $apiaccesskey]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user){
    log_msg("AUTH FAILED: ".$username);
    dhru_error("Authentication Failed");
}

/* =========================
   VALIDAR IMEI
========================= */
function validate_imei($imei){
    if(!preg_match('/^[A-Za-z0-9\-]{5,40}$/', $imei)){
        dhru_error("Invalid IMEI format");
    }
}

/* =========================
   ENVIAR A TU ENDPOINT
========================= */
function enviar_a_samurai($serial){
    $serial = urlencode($serial);
    $url = "https://samurai-server.online/A12Register/tool.php?reg={$serial}&service=8";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        log_msg("SAMURAI ERROR: " . curl_error($ch));
    } else {
        log_msg("SAMURAI RESPONSE: " . $response);
    }

    curl_close($ch);
}

/* =========================
   ACTIONS DHRU
========================= */
switch($action){

    /* -------- CUENTA -------- */
    case 'accountinfo':
        dhru_success("Account Info", [
            "AccoutInfo" => [
                "credit"   => $user['balance'],
                "mail"     => $user['email'],
                "currency" => "USD"
            ]
        ]);
    break;

    /* -------- LISTADO DE SERVICIOS (obligatorio para DHRU) -------- */
   case 'imeiservicelist':

    log_msg("Enviando XML RAW DEFINITIVO (sin json_encode)");

    $stmt = $pdo->query("SELECT service_id, service_name, credit, service_group
                         FROM imei_services 
                         WHERE status='active'");

    // agrupar servicios
    $groups = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $group  = $row['service_group'] ?: "Samurai Services";
        $sid    = $row['service_id'];
        $name   = htmlspecialchars($row['service_name'], ENT_QUOTES);
        $price  = number_format($row['credit'], 2, '.', '');

        if (!isset($groups[$group])) {
            $groups[$group] = "";
        }

        $groups[$group] .= "<SERVICE ID=\"$sid\" NAME=\"$name\" PRICE=\"$price\" TIME=\"Instant\" REQUIRE=\"IMEI\" />";
    }

    // formar XML
    $xml = "<SERVICES>";
    foreach ($groups as $gname => $services) {
        $xml .= "<GROUP NAME=\"$gname\">$services</GROUP>";
    }
    $xml .= "</SERVICES>";

    // JSON manual sin escapar
    $json = '{
"SUCCESS":[
    {
        "MESSAGE":"Service List",
        "LIST":"'. $xml .'"
    }
],
"apiversion":"6.0"
}';

    // limpiar buffers
    while (ob_get_level()) ob_end_clean();

    header("Content-Type: application/json; charset=utf-8");

    echo $json;

    log_msg("XML RAW ENVIADO: " . $xml);

    exit;

break;


    /* -------- CREAR ORDEN -------- */
    case 'placeimeiorder':

        $service_id = $parameters['ID'] ?? '';
        $imei = $parameters['IMEI'] ?? '';

        if(!$service_id || !$imei){
            dhru_error("Missing order data");
        }

        validate_imei($imei);

        $stmt = $pdo->prepare("SELECT credit FROM imei_services 
                               WHERE service_id=? AND status='active'");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$service){
            dhru_error("Invalid Service");
        }

        $price = $service['credit'];

        if($user['balance'] < $price){
            dhru_error("Not enough credits");
        }

        $order_id = "ORD".time().rand(100,999);

        $stmt = $pdo->prepare("INSERT INTO orders 
            (order_id,user_id,service_id,imei,status,result)
            VALUES (?,?,?,?, 'Processing','')");
        $stmt->execute([
            $order_id,
            $user['id'],
            $service_id,
            $imei
        ]);

        $pdo->prepare("UPDATE api_users SET balance=balance-? WHERE id=?")
            ->execute([$price, $user['id']]);

        enviar_a_samurai($imei);

        dhru_success("Order received", ["REFERENCEID" => $order_id]);

    break;

    /* -------- ORDENES MASIVAS -------- */
    case 'placeimeiorderbulk':

        if(!is_array($parameters)){
            dhru_error("Invalid bulk format");
        }

        $results = [];

        foreach($parameters as $bulkId => $order){

            $service_id = $order['ID'] ?? '';
            $imei       = $order['IMEI'] ?? '';

            if(!$service_id || !$imei){
                $results[$bulkId]['ERROR'][] = ["MESSAGE" => "Missing data"];
                continue;
            }

            if(!preg_match('/^[A-Za-z0-9\-]{5,40}$/', $imei)){
                $results[$bulkId]['ERROR'][] = ["MESSAGE" => "Invalid IMEI"];
                continue;
            }

            $stmt = $pdo->prepare("SELECT credit FROM imei_services 
                                   WHERE service_id=? AND status='active'");
            $stmt->execute([$service_id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!$service){
                $results[$bulkId]['ERROR'][] = ["MESSAGE" => "Invalid Service"];
                continue;
            }

            $price = $service['credit'];

            if($user['balance'] < $price){
                $results[$bulkId]['ERROR'][] = ["MESSAGE" => "Not enough credits"];
                continue;
            }

            $order_id = "ORD".time().rand(100,999);

            $pdo->prepare("INSERT INTO orders 
                (order_id,user_id,service_id,imei,status,result)
                VALUES (?,?,?,?, 'Processing','')")
                ->execute([$order_id,$user['id'],$service_id,$imei]);

            $pdo->prepare("UPDATE api_users SET balance=balance-? WHERE id=?")
                ->execute([$price,$user['id']]);

            $user['balance'] -= $price;

            enviar_a_samurai($imei);

            $results[$bulkId]['SUCCESS'][] = [
                "MESSAGE"     => "Order received",
                "REFERENCEID" => $order_id
            ];
        }

        echo json_encode($results);
        exit;

    break;

    /* -------- CONSULTAR ORDEN -------- */
    case 'getimeiorderdetails':

        $ref = $parameters['REFERENCEID'] ?? '';

        if(!$ref){
            dhru_error("Missing Reference ID");
        }

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id=?");
        $stmt->execute([$ref]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$order){
            dhru_error("Order not found");
        }

        dhru_success("Order Info", [
            "Order" => [
                "REFERENCEID" => $order['order_id'],
                "IMEI"        => $order['imei'],
                "STATUS"      => $order['status'],
                "RESULT"      => $order['result']
            ]
        ]);

    break;

    /* -------- ERROR POR DEFECTO -------- */
    default:
        dhru_error("Invalid Action");
}
?>
