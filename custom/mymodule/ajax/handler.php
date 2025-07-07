<?php
// --- Improved Backend PHP AJAX Handler for MO Cost Fetch ---

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);

// Load Dolibarr environment
$res = @include("../../main.inc.php");
if (!$res) $res = @include("../../../main.inc.php");
if (!$res) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to load Dolibarr environment."]);
    exit;
}

header('Content-Type: application/json');

// Permissions check (uncomment when deploying)
// if (empty($user->rights->commande->lire) && empty($user->rights->propal->lire)) {
//     echo json_encode(["status" => "error", "message" => "Access denied."]);
//     exit;
// }

$action = GETPOST('action', 'alpha');
$moRef = GETPOST('mo_ref', 'alpha');

if ($action !== 'get_mo_cost' || empty($moRef)) {
    echo json_encode(["status" => "error", "message" => "Invalid request."]);
    exit;
}

$safeMoRef = $db->escape($moRef);
$sql = "SELECT manufacturing_cost FROM " . MAIN_DB_PREFIX . "mrp_mo WHERE ref = '" . $safeMoRef . "' LIMIT 1";

dol_syslog("AJAX handler: Fetching MO cost for ref = $safeMoRef", LOG_DEBUG);

$resql = $db->query($sql);

if (!$resql) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $db->lasterror()
    ]);
    exit;
}

if ($db->num_rows($resql) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "MO not found for ref: $moRef"
    ]);
    exit;
}

$obj = $db->fetch_object($resql);
$db->free($resql);

if ($obj->manufacturing_cost === null) {
    echo json_encode([
        "status" => "success",
        "cost" => null,
        "message" => "Manufacturing cost not set."
    ]);
    exit;
}

echo json_encode([
    "status" => "success",
    "cost" => floatval($obj->manufacturing_cost)
]);
