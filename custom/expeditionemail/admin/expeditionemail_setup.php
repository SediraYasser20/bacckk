<?php
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->load("admin");
$langs->load("expeditionemail@expeditionemail");

if (! $user->admin) accessforbidden();

// Action enregistrer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    dolibarr_set_const($db, 'EXPEDITIONEMAIL_DEFAULT_TEMPLATE', $_POST['EXPEDITIONEMAIL_DEFAULT_TEMPLATE'], 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'EXPEDITIONEMAIL_SENDER_EMAIL', $_POST['EXPEDITIONEMAIL_SENDER_EMAIL'], 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
}

$page_name = "ExpeditionEmailSetup";
llxHeader('', $langs->trans($page_name), '', '', '', '', '', 0, 0);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

print '<form method="POST">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr>';
print '<td>'.$langs->trans("DefaultTemplate").'</td>';
print '<td><input type="text" name="EXPEDITIONEMAIL_DEFAULT_TEMPLATE" value="'.getDolGlobalString('EXPEDITIONEMAIL_DEFAULT_TEMPLATE').'" /></td>';
print '</tr>';

print '<tr>';
print '<td>'.$langs->trans("SenderEmail").'</td>';
print '<td><input type="email" name="EXPEDITIONEMAIL_SENDER_EMAIL" value="'.getDolGlobalString('EXPEDITIONEMAIL_SENDER_EMAIL').'" /></td>';
print '</tr>';

print '</table>';

print '<div class="center"><input type="submit" class="button" name="save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

llxFooter();
$db->close();

