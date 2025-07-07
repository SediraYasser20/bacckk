<?php
$res=0;
if (! $res && file_exists("../../main.inc.php")) $res=@include("../../main.inc.php");       // For root directory
if (! $res && file_exists("../../../main.inc.php")) $res=@include("../../../main.inc.php"); // For "custom"

$ngtmpdebug = GETPOST('ngtmpdebug', 'int');
if($ngtmpdebug) {
    ini_set('display_startup_errors', 1);
    ini_set('display_errors', 1);
    error_reporting(-1);
}
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';

if (!$conf->savorders->enabled) {
    accessforbidden();
}

dol_include_once('/core/class/html.form.class.php');
dol_include_once('/savorders/lib/savorders.lib.php');
dol_include_once('/savorders/class/savorders.class.php');

$object         = new savorders($db);
$savorders      = new savorders($db);
$extrafields    = new ExtraFields($db);

$formadmin      = new FormAdmin($db);
$form           = new Form($db);

$langs->load('admin');
$langs->load('stocks');
$langs->load('savorders@savorders');

$modname = $langs->trans("savordersSetup");

$action = GETPOST('action','alpha');
$id = GETPOST('id');

if (!$user->admin && !empty($action)) accessforbidden();

$form = new Form($db);

if ($action == 'configuration') {
    $error = 0;
    
    if(GETPOST('idwarehouse')){
        $result = dolibarr_set_const($db, "SAVORDERS_ADMIN_IDWAREHOUSE", GETPOST('idwarehouse'),'chaine',0,'',$conf->entity);
    }

    if (! $error) {
        setEventMessage($langs->trans("SetupSaved"), 'mesgs');
    } else {
        setEventMessage($langs->trans("Error"), 'errors');
    }

    $urltogo = ($backtopage ? $backtopage : dol_buildpath('/savorders/admin/admin.php', 2));
    header("Location: ".$urltogo);
    exit;
}

llxHeader('', $modname);

$linkback='<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';


print load_fiche_titre($modname, $linkback, 'title_setup');

$head = savordersPrepareHead();
print dol_get_fiche_head($head, 'general', $langs->trans("params"), -1, "setup");

print '<form id="col4-form" method="post" action="admin.php" enctype="multipart/form-data" >';
    print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '').'">';
    print '<input type="hidden" name="action" value="configuration">';
    print '<table class="border" width="100%">';
        
        print '<tr>';
            print '<td class="width200 ">'.$langs->trans('Warehouse').' '.$langs->trans('SAV').'  </td>';
            print '<td>';
                $idwarehouse = isset($conf->global->SAVORDERS_ADMIN_IDWAREHOUSE) ? $conf->global->SAVORDERS_ADMIN_IDWAREHOUSE : 0;
                $formproduct = new FormProduct($db);
$forcecombo = 0; // or 1, depending on what you need
         
		 print $formproduct->selectWarehouses(GETPOST('idwarehouse', 'int') ? GETPOST('idwarehouse', 'int') : $idwarehouse, 'idwarehouse', '', 1, 0, 0, '', 0, $forcecombo);
            print '</td>';
        print '</tr>';

    print '</table>';
    print '<br><div class="right"><input type="submit" class="butAction" value="'.$langs->trans("Validate").'"></div>';
print '</form>';
?>
<script>

</script>
<?php

llxFooter();
$db->close();

