<?php
/* Copyright (C) 2001-2025 various contributors */

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors.log');
error_reporting(E_ALL);

// ------------------------------------------------------------------------
// Includes and Setup
// ------------------------------------------------------------------------
try {
    require '../../main.inc.php';
    global $langs, $db, $conf, $user;

    $langs->loadLangs(['deliveryteam@deliveryteam', 'agenda', 'main', 'servicelivraison@servicelivraison']);
    if (!isset($db) || !($db instanceof DoliDB)) {
        error_log('Database connection failed in livraisonindex.php');
        throw new Exception('Database connection failed');
    }

    $form = new Form($db);
} catch (Exception $e) {
    error_log('Setup error in livraisonindex.php: ' . $e->getMessage());
    http_response_code(500);
    die('Internal Server Error: Setup failed');
}

// ------------------------------------------------------------------------
// Helper: Fetch ExtraField Options
// ------------------------------------------------------------------------
function fetchOptions(ExtraFields $ef, string $table, string $key): array
{
    try {
        $ef->fetch_name_optionals_label($table);
        $attrs = $ef->attributes[$table] ?? [];
        return $attrs['param'][$key]['options']
            ?? $attrs['list'][$key]
            ?? $attrs['arrayofkeyval'][$key]
            ?? [];
    } catch (Exception $e) {
        error_log('Error fetching extrafield options for ' . $table . '/' . $key . ': ' . $e->getMessage());
        return [];
    }
}

// ------------------------------------------------------------------------
// Prepare Static Options
// ------------------------------------------------------------------------
try {
    $extraF = new ExtraFields($db);
    $destinationOptions = fetchOptions($extraF, 'commande', 'destination');
    $payeeOptions = fetchOptions($extraF, 'commande', 'payee');

    $payeeLabels = [
        '1' => $langs->trans('No'),
        '2' => $langs->trans('Yes'),
    ];

    $statusOptions = [
        '1' => $langs->trans('En preparation'),
        '2' => $langs->trans('Expider'),
        '3' => $langs->trans('SortiEnLivraison'),
        '4' => $langs->trans('Livree'),
        '5' => $langs->trans('Cancelled'),
    ];
    $statusColors = ['1' => 'gray', '2' => 'blue', '3' => 'orange', '4' => 'green', '5' => 'red'];

    $shippingMethods = [];
    $res = $db->query("SELECT rowid, libelle FROM " . MAIN_DB_PREFIX . "c_shipment_mode WHERE active=1 ORDER BY libelle");
    if ($res) {
        while ($m = $db->fetch_object($res)) {
            $shippingMethods[$m->rowid] = $m->libelle;
        }
        $db->free($res);
    } else {
        error_log('Error fetching shipping methods: ' . $db->error());
    }

    $users = [];
    $res = $db->query("SELECT rowid, firstname, lastname FROM " . MAIN_DB_PREFIX . "user WHERE statut=1 ORDER BY lastname, firstname");
    if ($res) {
        while ($u = $db->fetch_object($res)) {
            $users[$u->rowid] = dolGetFirstLastname($u->firstname ?? '', $u->lastname ?? '');
        }
        $db->free($res);
    } else {
        error_log('Error fetching users: ' . $db->error());
    }
} catch (Exception $e) {
    error_log('Error preparing static options: ' . $e->getMessage());
}

// ------------------------------------------------------------------------
// Read Filters and Pagination
// ------------------------------------------------------------------------
$filters = [
    'date_from' => GETPOST('date_from', 'alpha'),
    'date_to' => GETPOST('date_to', 'alpha'),
    'customer_name' => GETPOST('customer_name', 'alpha'),
    'tracking' => GETPOST('tracking_filter', 'alpha'),
    'shipping_method' => GETPOST('shipping_method', 'int'),
    'destination' => GETPOST('destination_filter', 'alpha'),
    'status' => GETPOST('status', 'alpha'),
    'assigned_user' => GETPOST('assigned_user', 'int'),
    'payee' => GETPOST('payee_filter', 'int'),
];

// Pagination
$limit = 15;
$page_orders = max(0, GETPOST('page_orders', 'int'));
$page_events = max(0, GETPOST('page_events', 'int'));
$offset_orders = $page_orders * $limit;
$offset_events = $page_events * $limit;

// ------------------------------------------------------------------------
// Build Orders WHERE
// ------------------------------------------------------------------------
try {
    $allowedShippingMethods = ['S-LIV', 'S-LIV-YALIDINE', 'S-LIV-SPEEDMAIL', 'S-LIV-EXPRESS'];
    $shippingMethodIds = [];
    $res = $db->query(
        "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_shipment_mode 
         WHERE libelle IN ('" . implode("','", array_map([$db, 'escape'], $allowedShippingMethods)) . "') AND active=1"
    );
    if ($res) {
        while ($m = $db->fetch_object($res)) {
            $shippingMethodIds[] = $m->rowid;
        }
        $db->free($res);
    }

    $where = [
        "c.entity IN (" . getEntity('commande') . ")",
    ];
    if (!empty($shippingMethodIds)) {
        $where[] = "c.fk_shipping_method IN (" . implode(',', array_map('intval', $shippingMethodIds)) . ")";
    } else {
        $where[] = "0=1";
    }
    if ($f = $filters['date_from']) $where[] = "c.date_creation >= '" . $db->idate(dol_stringtotime($f, 0)) . "'";
    if ($f = $filters['date_to']) $where[] = "c.date_creation <= '" . $db->idate(dol_stringtotime($f, 1)) . "'";
    if ($f = $filters['customer_name']) $where[] = "s.nom LIKE '%" . $db->escape($f) . "%'";
    if ($f = $filters['tracking']) $where[] = "e.tracking LIKE '%" . $db->escape($f) . "%'";
    if ($f = $filters['shipping_method']) $where[] = "c.fk_shipping_method=" . (int)$f;
    if ($f = $filters['destination']) $where[] = "e.destination='" . $db->escape($f) . "'";
    if ($f = $filters['status']) $where[] = "e.delivery_status='" . $db->escape($f) . "'";
    if ($f = $filters['assigned_user']) $where[] = "e.assigned_user_id=" . (int)$f;
    if ($f = $filters['payee']) $where[] = "e.payee=" . (int)$f;
    $whereSql = implode(' AND ', $where);
} catch (Exception $e) {
    error_log('Error building WHERE clause: ' . $e->getMessage());
}

// ------------------------------------------------------------------------
// Handle CSV Export
// ------------------------------------------------------------------------
if (GETPOST('action') === 'csv') {
    try {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="orders_export_' . date('Ymd_His') . '.csv"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        $headers = [
            $langs->trans('Ref'),
            $langs->trans('Customer'),
            $langs->trans('ValidationDate'),
            $langs->trans('AmountHT'),
            $langs->trans('Tracking'),
            $langs->trans('ShippingMethod'),
            $langs->trans('Destination'),
            $langs->trans('Status'),
            $langs->trans('Payee'),
            $langs->trans('ProcessingTime'),
            $langs->trans('AssignTo')
        ];
        fputcsv($output, $headers, ';');

        $sqlExport = 
            "SELECT c.rowid, c.ref, c.total_ht, c.date_valid, c.fk_shipping_method, " .
            "s.nom AS company, e.delivery_status, e.tracking, e.destination, e.payee, " .
            "e.tms AS status_update, c.date_valid AS valid_ts, " .
            "e.assigned_user_id, u.firstname, u.lastname " .
            "FROM " . MAIN_DB_PREFIX . "commande AS c " .
            "LEFT JOIN " . MAIN_DB_PREFIX . "commande_extrafields AS e ON c.rowid = e.fk_object " .
            "LEFT JOIN " . MAIN_DB_PREFIX . "societe AS s ON c.fk_soc = s.rowid " .
            "LEFT JOIN " . MAIN_DB_PREFIX . "user AS u ON e.assigned_user_id = u.rowid " .
            "WHERE " . $whereSql . " ORDER BY c.date_creation DESC";
        $resExport = $db->query($sqlExport);
        if ($resExport) {
            while ($o = $db->fetch_object($resExport)) {
                $proc = '-';
                if (in_array($o->delivery_status, ['4', '5'])) {
                    $d1 = $db->jdate($o->valid_ts);
                    $d2 = $db->jdate($o->status_update);
                    if ($d1 && $d2) {
                        $diff = abs($d2 - $d1);
                        $days = floor($diff / 86400);
                        $hours = floor(($diff % 86400) / 3600);
                        $mins = floor(($diff % 3600) / 60);
                        $parts = [];
                        if ($days) $parts[] = "$days" . "d";
                        if ($hours) $parts[] = "$hours" . "h";
                        $parts[] = "$mins" . "m";
                        $proc = implode(' ', $parts);
                    }
                }
                $assigned_user = $o->assigned_user_id ? dolGetFirstLastname($o->firstname ?? '', $o->lastname ?? '') : '-';
                $payee_label = $payeeLabels[$o->payee] ?? $o->payee;
                $row = [
                    $o->ref,
                    $o->company,
                    dol_print_date($o->date_valid, 'dayhour'),
                    price($o->total_ht),
                    $o->tracking,
                    $shippingMethods[$o->fk_shipping_method] ?? '',
                    $destinationOptions[$o->destination] ?? $o->destination,
                    $statusOptions[$o->delivery_status] ?? $langs->trans('Unknown'),
                    $payee_label,
                    $proc,
                    $assigned_user
                ];
                fputcsv($output, $row, ';');
            }
            $db->free($resExport);
        }
        fclose($output);
        exit;
    } catch (Exception $e) {
        error_log('CSV export error: ' . $e->getMessage());
        http_response_code(500);
        die('Internal Server Error: CSV export failed');
    }
}

// ------------------------------------------------------------------------
// Count Total Orders for Pagination
// ------------------------------------------------------------------------
try {
    $countSql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "commande AS c " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "commande_extrafields AS e ON c.rowid = e.fk_object " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "societe AS s ON c.fk_soc = s.rowid " .
        "WHERE " . $whereSql;
    $resCount = $db->query($countSql);
    $total_orders = $resCount ? ($db->fetch_object($resCount)->total ?? 0) : 0;
    $db->free($resCount);
    $total_pages_orders = ceil($total_orders / $limit);
} catch (Exception $e) {
    error_log('Error counting orders: ' . $e->getMessage());
}

// ------------------------------------------------------------------------
// Fetch Orders
// ------------------------------------------------------------------------
try {
    $sqlO = 
        "SELECT c.rowid, c.ref, c.total_ht, c.date_valid, c.fk_shipping_method, " .
        "s.nom AS company, e.delivery_status, e.tracking, e.destination, e.payee, " .
        "e.tms AS status_update, c.date_valid AS valid_ts, " .
        "e.assigned_user_id, u.firstname, u.lastname " .
        "FROM " . MAIN_DB_PREFIX . "commande AS c " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "commande_extrafields AS e ON c.rowid = e.fk_object " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "societe AS s ON c.fk_soc = s.rowid " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "user AS u ON e.assigned_user_id = u.rowid " .
        "WHERE " . $whereSql . " ORDER BY c.date_creation DESC " .
        "LIMIT " . (int)$limit . " OFFSET " . (int)$offset_orders;
    $resO = $db->query($sqlO);
    $orders = [];
    if ($resO) {
        while ($o = $db->fetch_object($resO)) {
            $proc = '-';
            if (in_array($o->delivery_status, ['4', '5'])) {
                $d1 = $db->jdate($o->valid_ts);
                $d2 = $db->jdate($o->status_update);
                if ($d1 && $d2) {
                    $diff = abs($d2 - $d1);
                    $days = floor($diff / 86400);
                    $hours = floor(($diff % 86400) / 3600);
                    $mins = floor(($diff % 3600) / 60);
                    $parts = [];
                    if ($days) $parts[] = "$days" . "d";
                    if ($hours) $parts[] = "$hours" . "h";
                    $parts[] = "$mins" . "m";
                    $proc = implode(' ', $parts);
                }
            }
            $assigned_user = $o->assigned_user_id ? dolGetFirstLastname($o->firstname ?? '', $o->lastname ?? '') : '-';
            $payee_label = $payeeLabels[$o->payee] ?? $o->payee;
            $orders[] = [
                'ref' => $o->ref,
                'company' => $o->company,
                'date' => $o->date_valid,
                'amount' => price($o->total_ht),
                'total_ht' => $o->total_ht,
                'tracking' => $o->tracking,
                'shipping' => $shippingMethods[$o->fk_shipping_method] ?? '',
                'destination' => $destinationOptions[$o->destination] ?? $o->destination,
                'status' => $statusOptions[$o->delivery_status] ?? $langs->trans('Unknown'),
                'color' => $statusColors[$o->delivery_status] ?? 'black',
                'payee' => $payee_label,
                'processing' => $proc,
                'assigned_user' => $assigned_user,
                'link' => DOL_URL_ROOT . '/commande/card.php?id=' . $o->rowid
            ];
        }
        $db->free($resO);
    }
} catch (Exception $e) {
    error_log('Error fetching orders: ' . $e->getMessage());
}

// ------------------------------------------------------------------------
// Count Total Events for Pagination
// ------------------------------------------------------------------------
try {
    $countSqlE = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "actioncomm AS a " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "actioncomm_extrafields AS ef ON ef.fk_object = a.id " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "societe AS sc ON sc.rowid = a.fk_soc " .
        "WHERE a.entity = " . (int)$conf->entity . " AND ef.towhom = '" . $db->escape('service-livraison') . "'";
    if ($filters['date_from']) $countSqlE .= " AND a.datep >= '" . $db->idate(dol_stringtotime($filters['date_from'], 0)) . "'";
    if ($filters['date_to']) $countSqlE .= " AND a.datep <= '" . $db->idate(dol_stringtotime($filters['date_to'], 1)) . "'";
    if ($filters['customer_name']) $countSqlE .= " AND sc.nom LIKE '%" . $db->escape($filters['customer_name']) . "%'";
    $resCountE = $db->query($countSqlE);
    $total_events = $resCountE ? ($db->fetch_object($resCountE)->total ?? 0) : 0;
    $db->free($resCountE);
    $total_pages_events = ceil($total_events / $limit);
} catch (Exception $e) {
    error_log('Error counting events: ' . $e->getMessage());
}

// ------------------------------------------------------------------------
// Fetch Events
// ------------------------------------------------------------------------
try {
    $keyForDelivery = 'service-livraison';
    $ef = new ExtraFields($db);
    if ($ef->fetch_name_optionals_label('actioncomm') > 0) {
        $opts = $ef->attributes['actioncomm']['param']['towhom']['options'] ?? [];
        $found = array_search('service-livraison', $opts, true);
        if ($found !== false) $keyForDelivery = $found;
    }
    $sqlE =
        "SELECT a.id AS eventref, a.label AS title, a.datep, a.datep2, a.location, a.percent, sc.nom AS company, sc.rowid AS socid " .
        "FROM " . MAIN_DB_PREFIX . "actioncomm AS a " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "actioncomm_extrafields AS ef ON ef.fk_object = a.id " .
        "LEFT JOIN " . MAIN_DB_PREFIX . "societe AS sc ON sc.rowid = a.fk_soc " .
        "WHERE a.entity = " . (int)$conf->entity . " AND ef.towhom = '" . $db->escape($keyForDelivery) . "'";
    if ($filters['date_from']) $sqlE .= " AND a.datep >= '" . $db->idate(dol_stringtotime($filters['date_from'], 0)) . "'";
    if ($filters['date_to']) $sqlE .= " AND a.datep <= '" . $db->idate(dol_stringtotime($filters['date_to'], 1)) . "'";
    if ($filters['customer_name']) $sqlE .= " AND sc.nom LIKE '%" . $db->escape($filters['customer_name']) . "%'";
    $sqlE .= " ORDER BY a.datep DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset_events;
    $resE = $db->query($sqlE);
    $events = [];
    if ($resE) {
        while ($e = $db->fetch_object($resE)) {
            $d1 = $db->jdate($e->datep);
            $d2 = $db->jdate($e->datep2);
            $events[] = [
                'ref' => $e->eventref,
                'title' => $e->title,
                'from' => $d1,
                'to' => $d2,
                'location' => $e->location,
                'percent' => $e->percent,
                'company' => $e->company,
                'link' => DOL_URL_ROOT . '/comm/action/card.php?id=' . $e->eventref,
            ];
        }
        $db->free($resE);
    }
} catch (Exception $e) {
    error_log('Error fetching events: ' . $e->getMessage());
}

// ------------------------------------------------------------------------
// Render Page
// ------------------------------------------------------------------------
try {
    llxHeader('', '', $langs->trans('SERVICE LIVRAISON INFORMATICS'));
    print load_fiche_titre($langs->trans('SERVICE LIVRAISON INFORMATICS'), '', 'deliveryteam.png@deliveryteam');
    print '<div class="fichecenter" style="width:100%"><div style="width:100%">';

    // Filter Form
    print '<form method="GET" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="filter-form">';
    print '<table class="noborder" width="100%"><tr>';
    print '<td>' . $langs->trans('From') . ': <input type="date" name="date_from" value="' . dol_escape_htmltag($filters['date_from']) . '"></td>';
    print '<td>' . $langs->trans('To') . ': <input type="date" name="date_to" value="' . dol_escape_htmltag($filters['date_to']) . '"></td>';
    print '<td>' . $langs->trans('Customer') . ': <input type="text" name="customer_name" value="' . dol_escape_htmltag($filters['customer_name']) . '"></td>';
    print '</tr><tr>';
    print '<td>' . $langs->trans('Tracking') . ': <input type="text" name="tracking_filter" value="' . dol_escape_htmltag($filters['tracking']) . '"></td>';
    print '<td>' . $langs->trans('ShippingMethod') . ': <select name="shipping_method"><option value="">' . $langs->trans('All') . '</option>';
    foreach ($shippingMethods as $id => $lbl) {
        print '<option value="' . (int)$id . '"' . ($filters['shipping_method'] == $id ? ' selected' : '') . '>' . dol_escape_htmltag($lbl) . '</option>';
    }
    print '</select></td>';
    print '<td>' . $langs->trans('Destination') . ': <select name="destination_filter"><option value="">' . $langs->trans('All') . '</option>';
    foreach ($destinationOptions as $k => $l) {
        print '<option value="' . dol_escape_htmltag($k) . '"' . ($filters['destination'] == $k ? ' selected' : '') . '>' . dol_escape_htmltag($l) . '</option>';
    }
    print '</select></td>';
    print '</tr><tr>';
    print '<td>' . $langs->trans('Status') . ': <select name="status"><option value="">' . $langs->trans('All') . '</option>';
    foreach ($statusOptions as $code => $lbl) {
        print '<option value="' . (int)$code . '"' . ($filters['status'] == $code ? ' selected' : '') . '>' . dol_escape_htmltag($lbl) . '</option>';
    }
    print '</select></td>';
    print '<td>' . $langs->trans('Payee') . ': <select name="payee_filter"><option value="">' . $langs->trans('All') . '</option>';
    foreach ($payeeLabels as $code => $lbl) {
        print '<option value="' . (int)$code . '"' . ($filters['payee'] == $code ? ' selected' : '') . '>' . dol_escape_htmltag($lbl) . '</option>';
    }
    print '</select></td>';
    print '<td>' . $langs->trans('AssignTo') . ': <select name="assigned_user"><option value="">' . $langs->trans('All') . '</option>';
    foreach ($users as $id => $name) {
        print '<option value="' . (int)$id . '"' . ($filters['assigned_user'] == $id ? ' selected' : '') . '>' . dol_escape_htmltag($name) . '</option>';
    }
    print '</select></td>';
    print '</tr><tr>';
    print '<td colspan="3" style="text-align:right;">'
        . '<input type="submit" class="button" value="' . $langs->trans('Filter') . '"> '
        . '<a class="button" href="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?' . http_build_query(array_merge($filters, ['action' => 'csv'])) . '">' . $langs->trans('ExportCSV') . '</a>'
        . '</td>';
    print '</tr></table></form><br>';

    // Render Orders
    if (!empty($orders)) {
        print '<h3>' . $langs->trans('OrdersList') . '</h3>';
        print '<table class="noborder list" style="width:100%">';
        print '<tr class="liste_titre">';
        foreach (['Ref', 'Customer', 'ValidationDate', 'AmountHT', 'Tracking', 'ShippingMethod', 'Destination', 'Status', 'Payee', 'ProcessingTime', 'AssignTo'] as $col) {
            print '<th>' . $langs->trans($col) . '</th>';
        }
        print '</tr>';
        $sum = 0;
        foreach ($orders as $o) {
            $total_ht = is_numeric($o['total_ht']) ? (float)$o['total_ht'] : 0;
            $sum += $total_ht;
            print '<tr>';
            print '<td><a href="' . dol_escape_htmltag($o['link']) . '">' . dol_escape_htmltag($o['ref']) . '</a></td>';
            print '<td>' . dol_escape_htmltag($o['company']) . '</td>';
            print '<td>' . dol_print_date($o['date'], 'dayhour') . '</td>';
            print '<td style="text-align:right;">' . dol_escape_htmltag($o['amount']) . '</td>';
            print '<td>' . dol_escape_htmltag($o['tracking']) . '</td>';
            print '<td>' . dol_escape_htmltag($o['shipping']) . '</td>';
            print '<td>' . dol_escape_htmltag($o['destination']) . '</td>';
            print '<td><span style="color:' . dol_escape_htmltag($o['color']) . ';font-weight:bold;">' . dol_escape_htmltag($o['status']) . '</span></td>';
            print '<td>' . dol_escape_htmltag($o['payee']) . '</td>';
            print '<td style="text-align:right;">' . dol_escape_htmltag($o['processing']) . '</td>';
            print '<td>' . dol_escape_htmltag($o['assigned_user']) . '</td>';
            print '</tr>';
        }
        print '<tr class="liste_total">';
        print '<td colspan="3" style="text-align:right;font-weight:bold;">' . $langs->trans('Total') . ':</td>';
        print '<td style="text-align:right;font-weight:bold;">' . price($sum) . '</td>';
        print '<td colspan="7"></td>';
        print '</tr>';
        print '</table>';

        // Pagination for Orders
        if ($total_pages_orders > 1) {
            print '<div class="pagination" style="margin-top:10px; text-align:center;">';
            $base_url = $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($filters, ['page_events' => $page_events]));
            if ($page_orders > 0) {
                print '<a href="' . dol_escape_htmltag($base_url . '&page_orders=' . ($page_orders - 1)) . '" class="button">' . $langs->trans('Previous') . '</a> ';
            }
            for ($i = 0; $i < $total_pages_orders; $i++) {
                if ($i == $page_orders) {
                    print '<span style="font-weight:bold;">' . ($i + 1) . '</span> ';
                } else {
                    print '<a href="' . dol_escape_htmltag($base_url . '&page_orders=' . $i) . '">' . ($i + 1) . '</a> ';
                }
            }
            if ($page_orders < $total_pages_orders - 1) {
                print '<a href="' . dol_escape_htmltag($base_url . '&page_orders=' . ($page_orders + 1)) . '" class="button">' . $langs->trans('Next') . '</a>';
            }
            print '</div>';
        }
        print '<br>';
    } else {
        print '<div class="opacitymedium" style="padding:10px;">' . $langs->trans('NoOrderFound') . '</div><br>';
    }

    // Render Events
    if (!empty($events)) {
        print '<h3>' . $langs->trans('ServiceDeliveryEvents') . '</h3>';
        print '<table class="noborder list" style="width:100%">';
        print '<tr class="liste_titre">'
            . '<th>' . $langs->trans('EventRef') . '</th>'
            . '<th>' . $langs->trans('Title') . '</th>'
            . '<th>' . $langs->trans('DateFrom') . '</th>'
            . '<th>' . $langs->trans('DateTo') . '</th>'
            . '<th>' . $langs->trans('Location') . '</th>'
            . '<th style="text-align:right;">' . $langs->trans('Percent') . '</th>'
            . '<th>' . $langs->trans('Company') . '</th>'
            . '</tr>';
        foreach ($events as $e) {
            print '<tr>';
            print '<td><a href="' . dol_escape_htmltag($e['link']) . '">' . dol_escape_htmltag($e['ref']) . '</a></td>';
            print '<td>' . dol_escape_htmltag($e['title']) . '</td>';
            print '<td>' . dol_print_date($e['from'], 'dayhour') . '</td>';
            print '<td>' . dol_print_date($e['to'], 'dayhour') . '</td>';
            print '<td>' . dol_escape_htmltag($e['location']) . '</td>';
            $percent = is_numeric($e['percent']) ? (int)$e['percent'] : 0;
            print '<td style="text-align:right;">' . $percent . '%</td>';
            print '<td>' . dol_escape_htmltag($e['company']) . '</td>';
            print '</tr>';
        }
        print '</table>';

        // Pagination for Events
        if ($total_pages_events > 1) {
            print '<div class="pagination" style="margin-top:10px; text-align:center;">';
            $base_url = $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($filters, ['page_orders' => $page_orders]));
            if ($page_events > 0) {
                print '<a href="' . dol_escape_htmltag($base_url . '&page_events=' . ($page_events - 1)) . '" class="button">' . $langs->trans('Previous') . '</a> ';
            }
            for ($i = 0; $i < $total_pages_events; $i++) {
                if ($i == $page_events) {
                    print '<span style="font-weight:bold;">' . ($i + 1) . '</span> ';
                } else {
                    print '<a href="' . dol_escape_htmltag($base_url . '&page_events=' . $i) . '">' . ($i + 1) . '</a> ';
                }
            }
            if ($page_events < $total_pages_events - 1) {
                print '<a href="' . dol_escape_htmltag($base_url . '&page_events=' . ($page_events + 1)) . '" class="button">' . $langs->trans('Next') . '</a>';
            }
            print '</div>';
        }
    } else {
        print '<div class="opacitymedium" style="padding:10px;">' . $langs->trans('NoRecordFound') . '</div>';
    }

    print '</div></div>';
    llxFooter();
    $db->close();
} catch (Exception $e) {
    error_log('Rendering error: ' . $e->getMessage());
    http_response_code(500);
    die('Internal Server Error: Page rendering failed');
}
?>
