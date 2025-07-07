<?php
// File: dolibarr/custom/servicelivraison/order/tabServiceLivraison.php

// 1) Load Dolibarr environment
require_once __DIR__ . '/../../../main.inc.php';

// This should be right after main.inc.php include
global $user, $db, $langs, $conf; // Ensure necessary globals are available if not already. $langs might not be fully loaded here for translatable accessforbidden messages yet.

$is_member_of_group_13 = false;
// Ensure $user is loaded and $user->id is available
if (!empty($user->id)) {
    $sql_check_group = "SELECT COUNT(*) as count 
                        FROM " . MAIN_DB_PREFIX . "usergroup_user 
                        WHERE fk_user = " . (int)$user->id . " AND fk_usergroup = 13";
    $res_check_group = $db->query($sql_check_group);

    if ($res_check_group) {
        $obj_check = $db->fetch_object($res_check_group);
        if ($obj_check && $obj_check->count > 0) {
            $is_member_of_group_13 = true;
        }
        $db->free($res_check_group);
    } else {
        // Log error, but don't necessarily block access just for this query failure.
        // Or, decide on a stricter policy (e.g., deny if check fails). For now, log.
        dol_syslog("Error checking group membership for servicelivraison tab: " . $db->lasterror(), LOG_ERR);
    }
} else {
    // User object not loaded or no ID, critical error, deny access
    // This case should ideally not happen if main.inc.php loads correctly
    if (is_object($langs)) $langs->load("errors"); // Attempt to load langs for a translated message
    accessforbidden((is_object($langs) && $langs->transnoentitiesnoconv("ErrorUserNotLoaded")) ? $langs->transnoentitiesnoconv("ErrorUserNotLoaded") : "Error: User not loaded.");
    // llxFooter might not be appropriate if $main_inc_already_triggered_output is not set
    // Forcing exit is safer here as the page state is uncertain.
    exit;
}

// Apply access restriction
// Rule: Non-admins MUST be in group 13. Admins currently bypass this specific check.
// If admins also need to be restricted by group 13, the condition becomes: if (!$is_member_of_group_13)
if (!$user->admin && !$is_member_of_group_13) {
    if (is_object($langs)) $langs->load("errors"); // Attempt to load langs for a translated message
    accessforbidden((is_object($langs) && $langs->transnoentitiesnoconv("AccessForbidden")) ? $langs->transnoentitiesnoconv("AccessForbidden") : "Access Forbidden");
    // It's often better to let accessforbidden() handle the footer, or not call llxFooter() if headers might be an issue.
    // accessforbidden() usually calls exit by itself.
    exit; // Ensure script stops.
}

// 2) Include required classes
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

// 3) Load translations and render header
$langs->load('servicelivraison@servicelivraison');
$langs->load('users');
llxHeader('', $langs->trans('ServiceLivraison'));

// 4) Check read permission
if (!$user->rights->commande->lire) {
    accessforbidden();
    exit;
}

// 5) Fetch Sales Order
$id = GETPOST('id', 'int');
$commande = new Commande($db);
if ($commande->fetch($id) <= 0) {
    setEventMessage($langs->trans('OrderNotFound'), 'errors');
    llxFooter();
    exit;
}

// 6) Load extrafields
$extrafields = new ExtraFields($db);
$extralabels = $extrafields->fetch_name_optionals_label('commande');
$commande->fetch_optionals($id, $extralabels);
$options = $commande->array_options;

// Set default for payee if not set
if (!isset($options['options_payee'])) {
    $options['options_payee'] = 1; // Default to "No" (1)
}

// 7) Get subordinate users
$subordinate_users = [];
$sql = "SELECT rowid, firstname, lastname, login FROM " . MAIN_DB_PREFIX . "user WHERE fk_user = " . (int)$user->id . " AND statut = 1 ORDER BY lastname, firstname";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $subordinate_users[$obj->rowid] = "$obj->firstname $obj->lastname ($obj->login)";
    }
    $db->free($resql);
} else {
    setEventMessage($langs->trans('ErrorFetchingUsers'), 'errors');
}

// 8) Handle form submission
if (GETPOST('action') === 'set_service_livraison') {
    // Permission & CSRF check
    if (!$user->rights->commande->creer) {
        setEventMessage($langs->trans('NoPermission'), 'errors');
    } elseif (empty($_SESSION['newtoken']) || $_SESSION['newtoken'] !== GETPOST('token')) {
        setEventMessage($langs->trans('ErrorBadForm'), 'errors');
    } else {
        $original = $commande->array_options;
        $valid = true;

        // Assign to subordinate (only if not already set)
        $assigned = GETPOST('assigned_user_id', 'int');
        if (empty($original['options_assigned_user_id']) && $assigned) {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int)$assigned . " AND fk_user = " . (int)$user->id;
            $res = $db->query($sql);
            if ($res && $db->num_rows($res) > 0) {
                $commande->array_options['options_assigned_user_id'] = $assigned;
            } else {
                setEventMessage($langs->trans('ErrorInvalidUserAssignment'), 'errors');
                $valid = false;
            }
            $db->free($res);
        }

        // Delivery status change rules
        $currStatus = (int)($original['options_delivery_status'] ?? 0);
        $newStatus = (int)GETPOST('options_delivery_status', 'int');
        if (in_array($currStatus, [4, 5], true) && $currStatus !== $newStatus) {
            setEventMessage($langs->trans('ErrorCannotChangeStatusAfterDelivery'), 'errors');
            $valid = false;
        } else {
            $commande->array_options['options_delivery_status'] = $newStatus;
        }

        // Payee rules (cannot change if set to Yes, value 2; default to No if not provided)
        $currPayee = (int)($original['options_payee'] ?? 1); // Default to "No" (1)
        $newPayee = GETPOST('options_payee', 'int') ?: 1; // Default to 1 if not provided
        if ($currPayee === 2 && $newPayee !== 2) {
            setEventMessage($langs->trans('ErrorCannotChangePayeeAfterYes'), 'errors');
            $valid = false;
        } else {
            $commande->array_options['options_payee'] = (int)$newPayee;
        }

        // Commentaire rules (cannot change if payee is Yes)
        $newCommentaire = GETPOST('options_commentaire', 'alpha');
        if ($currPayee === 2 && $newCommentaire !== ($original['options_commentaire'] ?? '')) {
            setEventMessage($langs->trans('ErrorCannotChangeCommentaireAfterPayeeYes'), 'errors');
            $valid = false;
        } else {
            $commande->array_options['options_commentaire'] = $newCommentaire;
        }

        // Destination rules (preserve once set)
        foreach (['destination'] as $field) {
            $key = "options_$field";
            $newVal = GETPOST($key, $field === 'destination' ? 'alpha' : 'alpha');
            if (!empty($original[$key]) && $original[$key] !== $newVal && $newVal !== '') {
                setEventMessage($langs->trans('ErrorCannotChange' . ucfirst($field) . 'AfterSet'), 'errors');
                $valid = false;
            } elseif (empty($original[$key])) {
                $commande->array_options[$key] = $newVal;
            }
        }

        // Save if all valid
        if ($valid) {
            if ($commande->insertExtraFields() >= 0 && $commande->update($user) > 0) {
                setEventMessage($langs->trans('RecordSaved'), 'mesgs');
                $commande->fetch_optionals($id, $extralabels);
                $options = $commande->array_options;
            } else {
                setEventMessage($commande->error ?: $langs->trans('ErrorSaving'), 'errors');
            }
        } else {
            // Reload without saving
            $commande->fetch_optionals($id, $extralabels);
            $options = $commande->array_options;
        }
    }
}

// 9) Display form
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '">';
print '<input type="hidden" name="token" value="' . htmlspecialchars($_SESSION['newtoken']) . '">';
print '<input type="hidden" name="action" value="set_service_livraison">';
print '<table class="noborder" width="100%">';

// Hidden service_exp preservation
if (isset($options['options_service_exp'])) {
    print '<input type="hidden" name="options_service_exp" value="' . htmlspecialchars($options['options_service_exp']) . '">';
}

// Delivery Status
$st = (int)($options['options_delivery_status'] ?? 0);
$readonlySt = in_array($st, [4, 5], true);
print '<tr><td><strong>' . $langs->trans('DeliveryStatus') . '</strong></td><td>';
if ($readonlySt) {
    print $extrafields->showOutputField('delivery_status', $st, '', 'commande', $langs);
    print '<input type="hidden" name="options_delivery_status" value="' . $st . '">';
} else {
    print $extrafields->showInputField('delivery_status', $st, '', '', '', '', $commande->id, 'commande');
}
print '</td></tr>';

// Payee
$payee = (int)($options['options_payee'] ?? 1); // Default to "No" (1)
$payeeReadonly = ($payee === 2);
print '<tr><td><strong>' . $langs->trans('Payee') . '</strong></td><td>';
if ($payeeReadonly) {
    print $extrafields->showOutputField('payee', $payee, '', 'commande', $langs);
    print '<input type="hidden" name="options_payee" value="' . $payee . '">';
} else {
    print $extrafields->showInputField('payee', $payee, '', '', '', '', $commande->id, 'commande');
}
print '</td></tr>';

// Commentaire
$commentaire = $options['options_commentaire'] ?? '';
print '<tr><td><strong>' . $langs->trans('Commentaire') . '</strong></td><td>';
if ($payeeReadonly) {
    print $extrafields->showOutputField('commentaire', $commentaire, '', 'commande', $langs);
    print '<input type="hidden" name="options_commentaire" value="' . htmlspecialchars($commentaire) . '">';
} else {
    print $extrafields->showInputField('commentaire', $commentaire, '', '', '', '', $commande->id, 'commande');
}
print '</td></tr>';

// Destination
foreach (['destination'] as $fld) {
    $key = "options_$fld";
    $val = $options[$key] ?? '';
    $set = !empty($val);
    print '<tr><td><strong>' . $langs->trans(ucfirst($fld)) . '</strong></td><td>';
    if ($set) {
        print $extrafields->showOutputField($fld, $val, '', 'commande', $langs);
        print '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($val) . '">';
    } else {
        print $extrafields->showInputField($fld, $val, '', '', '', '', $commande->id, 'commande');
    }
    print '</td></tr>';
}

// Assignment (readonly once set)
if (!empty($subordinate_users)) {
    print '<tr><td><strong>' . $langs->trans('AssignTo') . '</strong></td><td>';
    $curr = (int)($options['options_assigned_user_id'] ?? 0);
    if ($curr) {
        $u = new User($db);
        if ($u->fetch($curr) > 0) {
            print $u->getFullName($langs);
            print '<input type="hidden" name="assigned_user_id" value="' . $curr . '">';
        }
    } else {
        print '<select class="flat" name="assigned_user_id"><option value="">' . $langs->trans('SelectUser') . '</option>';
        foreach ($subordinate_users as $idU => $nameU) {
            print '<option value="' . $idU . '">' . htmlspecialchars($nameU) . '</option>';
        }
        print '</select>';
    }
    print '</td></tr>';
}

// Submit
print '<tr><td colspan="2" class="center">';
print '<input type="submit" class="button" value="' . $langs->trans('Save') . '">';
print '</td></tr>';
print '</table>';
print '</form>';

llxFooter();
$db->close();
