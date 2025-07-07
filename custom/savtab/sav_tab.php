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
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

// Load order library - This logic is fine
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


// IMPROVEMENT: Removed the custom prepare_order_head() function.
// The standard order_prepare_head() function, modified by your hook, will be used instead.


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
            
            $object->array_options['options_savorders_sav'] = GETPOST('savorders_sav', 'int') ? 1 : 0;
            $facture_sav_value = GETPOST('facture_sav', 'int');
            $object->array_options['options_facture_sav'] = $facture_sav_value > 0 ? $facture_sav_value : 0;
            
            if (!$error) {
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

    // Prepare head using the standard Dolibarr function
    if ($order_lib_loaded && function_exists('order_prepare_head')) {
        $head = order_prepare_head($object);
    } else {
        // Fallback in case standard library is not found (unlikely)
        $head = array();
    }

    // This will correctly display all tabs, including the one added by your hook
    print dol_get_fiche_head($head, 'sav', $langs->trans("CustomerOrder"), -1, 'order');
    
    // The rest of your view logic remains the same...
    // ... (omitted for brevity, it was correct) ...
}

// ... the rest of the file ...