<?php

/* Copyright (C) 2024 Your Company

 *

 * This program is free software; you can redistribute it and/or modify

 * it under the terms of the GNU General Public License as published by

 * the Free Software Foundation; either version 3 of the License, or

 * (at your option) any later version.

 */



/**

 * \file      htdocs/custom/savtab/sav_tab.php

 * \ingroup   savtab

 * \brief     SAV tab for Sales Orders

 */



// Load Dolibarr environment

$res = 0;

if (!$res && file_exists("../../main.inc.php")) {

    $res = @include "../../main.inc.php";

}

if (!$res && file_exists("../../../main.inc.php")) {

    $res = @include "../../../main.inc.php";

}

if (!$res && file_exists("../../../../main.inc.php")) {

    $res = @include "../../../../main.inc.php";

}

if (!$res) {

    die("Include of main fails");

}



require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';



// Load order library

$order_lib_loaded = false;

$order_lib_paths = array(

    '/commande/lib/commande.lib.php',

    '/core/lib/order.lib.php',

    '/commande/lib/order.lib.php'

);



foreach ($order_lib_paths as $lib_path) {

    if (file_exists(DOL_DOCUMENT_ROOT.$lib_path)) {

        require_once DOL_DOCUMENT_ROOT.$lib_path;

        $order_lib_loaded = true;

        break;

    }

}



// Load translation files

$langs->loadLangs(array("orders", "companies", "deliveries", "bills", "savtab@savtab"));



// Get parameters

$id = GETPOST('id', 'int');

$ref = GETPOST('ref', 'alpha');

$action = GETPOST('action', 'aZ09');

$cancel = GETPOST('cancel', 'aZ09');

$backtopage = GETPOST('backtopage', 'alpha');



// Initialize objects

$object = new Commande($db);

$extrafields = new ExtraFields($db);

$hookmanager->initHooks(array('ordercard', 'globalcard'));



// Load object

include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php';



// Fetch optional attributes

$extrafields->fetch_name_optionals_label($object->table_element);

if ($id > 0 || !empty($ref)) {

    $object->fetch_optionals();

}



// Define permissions

$permissiontoread = $user->hasRight('commande', 'lire');

$permissiontoadd = $user->hasRight('commande', 'write');

$permissiontodelete = $user->hasRight('commande', 'supprimer');



// Security check

if ($user->socid > 0) accessforbidden();

if (!isModEnabled('commande')) accessforbidden();

if (!$permissiontoread) accessforbidden();



/**

 * Prepare order head tabs

 */

function prepare_order_head($object)

{

    global $langs;

    

    $head = array();

    $h = 0;

    

    $head[$h][0] = DOL_URL_ROOT.'/commande/card.php?id='.$object->id;

    $head[$h][1] = $langs->trans("OrderCard");

    $head[$h][2] = 'order';

    $h++;

    

    if (!getDolGlobalString('MAIN_DISABLE_CONTACTS_TAB')) {

        $head[$h][0] = DOL_URL_ROOT.'/commande/contact.php?id='.$object->id;

        $head[$h][1] = $langs->trans("ContactsAddresses");

        $head[$h][2] = 'contact';

        $h++;

    }

    

    $head[$h][0] = DOL_URL_ROOT.'/commande/shipment.php?id='.$object->id;

    $head[$h][1] = $langs->trans('Shipments');

    $head[$h][2] = 'shipping';

    $h++;

    

    $head[$h][0] = DOL_URL_ROOT.'/custom/savtab/sav_tab.php?id='.$object->id;

    $head[$h][1] = $langs->trans("SAV");

    $head[$h][2] = 'sav';

    $h++;

    

    if (!getDolGlobalString('MAIN_DISABLE_NOTES_TAB')) {

        $head[$h][0] = DOL_URL_ROOT.'/commande/note.php?id='.$object->id;

        $head[$h][1] = $langs->trans("Notes");

        $head[$h][2] = 'note';

        $h++;

    }

    

    $head[$h][0] = DOL_URL_ROOT.'/commande/document.php?id='.$object->id;

    $head[$h][1] = $langs->trans("Documents");

    $head[$h][2] = 'documents';

    $h++;

    

    $head[$h][0] = DOL_URL_ROOT.'/commande/info.php?id='.$object->id;

    $head[$h][1] = $langs->trans("Info");

    $head[$h][2] = 'info';

    $h++;

    

    return $head;

}



/*

 * Actions

 */

$parameters = array();

$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);

if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');



if (empty($reshook)) {
    if ($action == 'update_sav' && !$cancel) {
        if ($permissiontoadd) {
            $error = 0;

            // 1) Update SAV order flag from checkbox
            $object->array_options['options_savorders_sav'] = GETPOST('savorders_sav','int') ? 1 : 0;

            // 2) Update facture_sav from dropdown
            $facture_sav_value = GETPOST('facture_sav','int');
            $object->array_options['options_facture_sav'] = $facture_sav_value > 0 ? $facture_sav_value : 0;

            // ← INSERT THIS ENTIRE SNIPPET RIGHT HERE ↓
            if ($facture_sav_value > 0) {
                // a) Force status = REIMBURSED (3)
                $object->array_options['options_savorders_status'] = savorders::REIMBURSED;

                // b) Load invoice reference
                $sql   = "SELECT ref FROM ".MAIN_DB_PREFIX."facture WHERE rowid=".(int)$facture_sav_value;
                $resql = $db->query($sql);
                $invRef = $resql ? $db->fetch_object($resql)->ref : $facture_sav_value;

                // c) Append history line with invoice ref
                $today = dol_print_date(dol_now(), 'day');
                $hist  = $object->array_options['options_savorders_history'];
                $hist .= '<div class="savorders_history_txt">'
                       . '- '.$today.': '.$langs->trans('ClientReimbursedInvoice', $invRef)
                       . '</div>';

                $object->array_options['options_savorders_history'] = $hist;
            }
            // ↑ END INSERT

            if (!$error) {
                // Persist all extrafields in one go
                $result = $object->insertExtraFields();
                if ($result < 0) {
                    setEventMessages($object->error, $object->errors, 'errors');
                    $error++;
                }
            }

            if (!$error) {
                setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
                header('Location: '.$_SERVER["PHP_SELF"].'?id='.$object->id);
                exit;
            }
        } else {
            setEventMessages($langs->trans("NotEnoughPermissions"), null, 'errors');
        }
    }
}




/*

 * View

 */

$form = new Form($db);



$title = $langs->trans("Order").' - '.$langs->trans("SAV");

$help_url = '';



llxHeader('', $title, $help_url);



if ($id > 0 || !empty($ref)) {

    $object->fetch_thirdparty();



    // Prepare head

    if ($order_lib_loaded && function_exists('order_prepare_head')) {

        $head = order_prepare_head($object);

    } else {

        $head = prepare_order_head($object);

    }



    print dol_get_fiche_head($head, 'sav', $langs->trans("CustomerOrder"), -1, 'order');



    // Banner

    $linkback = '<a href="'.DOL_URL_ROOT.'/commande/list.php?restore_lastsearch_values=1'.(!empty($socid) ? '&socid='.$socid : '').'">'.$langs->trans("BackToList").'</a>';



    $morehtmlref = '<div class="refidno">';

    $morehtmlref .= $form->editfieldkey("RefCustomer", 'ref_client', $object->ref_client, $object, $permissiontoadd, 'string', '', 0, 1);

    $morehtmlref .= $form->editfieldval("RefCustomer", 'ref_client', $object->ref_client, $object, $permissiontoadd, 'string', '', null, null, '', 1);

    $morehtmlref .= '<br>'.$langs->trans('ThirdParty').' : '.$object->thirdparty->getNomUrl(1, 'customer');

    if (isModEnabled('project')) {

        $langs->load("projects");

        $morehtmlref .= '<br>'.$langs->trans('Project').' ';

        if ($permissiontoadd) {

            $morehtmlref .= ' : ';

            if ($action == 'classify') {

                $morehtmlref .= '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';

                $morehtmlref .= '<input type="hidden" name="action" value="classin">';

                $morehtmlref .= '<input type="hidden" name="token" value="'.newToken().'">';

                if (class_exists('FormProjets')) {

                    $formproject = new FormProjets($db);

                    $morehtmlref .= $formproject->select_projects($object->socid, $object->fk_project, 'projectid', 0, 0, 1, 0, 1, 0, 0, '', 1);

                }

                $morehtmlref .= '<input type="submit" class="button valignmiddle" value="'.$langs->trans("Modify").'">';

                $morehtmlref .= '</form>';

            } else {

                $morehtmlref .= $form->form_project($_SERVER['PHP_SELF'].'?id='.$object->id, $object->socid, $object->fk_project, 'none', 0, 0, 0, 1);

            }

        } else {

            if (!empty($object->fk_project)) {

                $proj = new Project($db);

                $proj->fetch($object->fk_project);

                $morehtmlref .= ' : '.$proj->getNomUrl(1);

            }

        }

    }

    $morehtmlref .= '</div>';



    dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);



    print '<div class="fichecenter">';

    print '<div class="underbanner clearboth"></div>';



    // SAV Information Form

    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';

    print '<input type="hidden" name="token" value="'.newToken().'">';

    print '<input type="hidden" name="action" value="update_sav">';

    print '<input type="hidden" name="id" value="'.$object->id.'">';

    if ($backtopage) {

        print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';

    }



    print '<table class="border centpercent tableforfield">';



    // SAV Order Checkbox

    print '<tr>';

    print '<td class="titlefield">'.$langs->trans("SAVOrder").'</td>';

    print '<td>';

    $checked = (!empty($object->array_options['options_savorders_sav'])) ? 'checked="checked"' : '';

    print '<input type="checkbox" name="savorders_sav" value="1" '.$checked;

    if (!$permissiontoadd) print ' disabled="disabled"';

    print '>';

    print '</td>';

    print '</tr>';



    // SAV Invoice (facture_sav) Dropdown

    print '<tr>';

    print '<td class="titlefield">'.$langs->trans("SAVInvoice").'</td>';

    print '<td>';

    

    // Get current value

    $current_facture_sav = isset($object->array_options['options_facture_sav']) ? $object->array_options['options_facture_sav'] : 0;

    

    // Create dropdown for invoices

    if ($permissiontoadd) {

        // Get invoices for this customer

        $sql = "SELECT f.rowid, f.ref, f.total_ttc, f.fk_statut";

        $sql .= " FROM ".MAIN_DB_PREFIX."facture as f";

        $sql .= " WHERE f.fk_soc = ".(int)$object->socid;

        $sql .= " AND f.entity IN (".getEntity('invoice').")";

        $sql .= " ORDER BY f.ref DESC";

        

        $resql = $db->query($sql);

        

        print '<select name="facture_sav" class="flat minwidth200">';

        print '<option value="0">'.$langs->trans("SelectInvoice").'</option>';

        

        if ($resql) {

            while ($obj = $db->fetch_object($resql)) {

                $selected = ($current_facture_sav == $obj->rowid) ? 'selected="selected"' : '';

                $status_label = '';

                

                // Add status indicator

                if ($obj->fk_statut == 0) $status_label = ' ['.$langs->trans("Draft").']';

                elseif ($obj->fk_statut == 1) $status_label = ' ['.$langs->trans("Validated").']';

                elseif ($obj->fk_statut == 2) $status_label = ' ['.$langs->trans("Paid").']';

                elseif ($obj->fk_statut == 3) $status_label = ' ['.$langs->trans("Abandoned").']';

                

                print '<option value="'.$obj->rowid.'" '.$selected.'>';

                print $obj->ref.' - '.price($obj->total_ttc).' '.$langs->trans("Currency".$conf->currency).$status_label;

                print '</option>';

            }

            $db->free($resql);

        }

        

        print '</select>';

    } else {

        // Read-only mode

        if ($current_facture_sav > 0) {

            $facture = new Facture($db);

            if ($facture->fetch($current_facture_sav) > 0) {

                print $facture->getNomUrl(1).' - '.price($facture->total_ttc).' '.$langs->trans("Currency".$conf->currency);

            } else {

                print '<span class="opacitymedium">'.$langs->trans("InvoiceNotFound").'</span>';

            }

        } else {

            print '<span class="opacitymedium">'.$langs->trans("NoInvoiceSelected").'</span>';

        }

    }

    

    print '</td>';

    print '</tr>';



    // SAV Status (Read-only)

    print '<tr>';

    print '<td class="titlefield">'.$langs->trans("SAVStatus").'</td>';

    print '<td>';

    $sav_status = isset($object->array_options['options_savorders_status']) ? $object->array_options['options_savorders_status'] : '';

 // SAV Status (Read‑only)
if ($sav_status == savorders::REIMBURSED) {
    $fact_id = $object->array_options['options_facture_sav'] ?? 0;
    if ($fact_id) {
        $fac = new Facture($db);
        if ($fac->fetch($fact_id) > 0) {
            // Show reimbursed amount with currency
            $amount = price($fac->total_ttc).' '.$langs->trans("Currency".$conf->currency);
            print '<span class="badge badge-status4">'
                . $langs->trans("ClientReimbursedAmount", $amount)
                . '</span>';
        } else {
            print '<span class="badge badge-status4">'.$langs->trans("Reimbursed").'</span>';
        }
    } else {
        print '<span class="badge badge-status4">'.$langs->trans("Reimbursed").'</span>';
    }
}
elseif (empty($sav_status)) {
    print '<span class="opacitymedium">'.$langs->trans("NotGenerated").'</span>';
}
else {
    // your existing Received/Delivered labels
    $map = [
        savorders::RECIEVED_CUSTOMER  => $langs->trans('ProductReceivedFromCustomer'),
        savorders::DELIVERED_CUSTOMER => $langs->trans('ProductDeliveredToCustomer'),
        savorders::DELIVERED_SUPPLIER => $langs->trans('ProductDeliveredToSupplier'),
        savorders::RECEIVED_SUPPLIER  => $langs->trans('ProductReceivedFromSupplier'),
    ];
    $label = $map[$sav_status] ?? $sav_status;
    print '<span class="badge badge-status4">'.dol_escape_htmltag($label).'</span>';
}


    print '</td>';

    print '</tr>';



    // SAV History (Read-only, with HTML rendering)

    print '<tr>';

    print '<td class="titlefield valignmiddle">'.$langs->trans("SAVHistory").'</td>';

    print '<td>';

    $sav_history = isset($object->array_options['options_savorders_history']) ? $object->array_options['options_savorders_history'] : '';

    if (empty(trim($sav_history))) {

        print '<span class="opacitymedium">'.$langs->trans("NoHistoryYet").'</span>';

    } else {

        // Directly print the history content.

        // It already contains the necessary HTML formatting from your Actionssavorders.php file.

        print $sav_history;

    }

    print '</td>';

    print '</tr>';



    print '</table>';



    // Action buttons

    if ($permissiontoadd) {

        print '<div class="center tabsAction">';

        print '<input type="submit" class="button button-save" name="save" value="'.$langs->trans("Save").'">';

        if ($backtopage) {

            print ' &nbsp; &nbsp; ';

            print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'">';

        }

        print '</div>';

    }



    print '</form>';



    print '</div>';

    print dol_get_fiche_end();

} else {

    print $langs->trans("ErrorRecordNotFound");

}



llxFooter();

$db->close();
