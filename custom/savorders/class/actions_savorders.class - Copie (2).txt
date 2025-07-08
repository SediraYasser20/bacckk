<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/savorders/class/savorders.class.php');

/**
 * Class Actionssavorders
 */
class Actionssavorders
{
    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
     */
    public $results = array();

    /**
     * @var string String displayed by executeHook() immediately after return
     */
    public $resprints;

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * Constructor
     */
    public function __construct()
    {
    }

    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $user, $conf;

        $langs->loadLangs(array('stocks'));
        $langs->load('savorders@savorders');

        $savorders = new savorders($db);

        $tmparray = ['receiptofproduct_valid', 'createdelivery_valid', 'deliveredtosupplier_valid', 'receivedfromsupplier_valid'];

        $ngtmpdebug = GETPOST('ngtmpdebug', 'int');
        if($ngtmpdebug) {
            echo '<pre>';
            print_r($parameters);
            echo '</pre>';
            
            ini_set('display_startup_errors', 1);
            ini_set('display_errors', 1);
            error_reporting(-1);
        }

        if ($object && (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) && in_array($action, $tmparray)) {

            $error = 0;
            $now = dol_now();

            $savorders_date = '';

            global $savorders_date;

            $tmpdate = dol_mktime(0,0,0, GETPOST('savorders_datemonth','int'), GETPOST('savorders_dateday','int'), GETPOST('savorders_dateyear','int'));
            
            $savorders_date = dol_print_date($tmpdate, 'day');

            $cancel = GETPOST('cancel', 'alpha');

            $novalidaction = str_replace("_valid", "", $action);

            $s = GETPOST('savorders_data', 'array');

            $savorders_sav = $object->array_options["options_savorders_sav"];
            $savorders_status = $object->array_options["options_savorders_status"];

            if(!$savorders_sav || $cancel) return 0;

            $idwarehouse = isset($conf->global->SAVORDERS_ADMIN_IDWAREHOUSE) ? $conf->global->SAVORDERS_ADMIN_IDWAREHOUSE : 0;

            if(($novalidaction == 'receiptofproduct' || $novalidaction == 'deliveredtosupplier') && $idwarehouse <= 0) {
                $error++;
                $action = $novalidaction;
            }

            $commande = $object;

            $nblines = count($commande->lines);

            if($object->element == 'order_supplier') {
                $labelmouve = ($novalidaction == 'deliveredtosupplier') ? $langs->trans('ProductDeliveredToSupplier') : $langs->trans('ProductReceivedFromSupplier');
            } else {
                $labelmouve = ($novalidaction == 'receiptofproduct') ? $langs->trans('ProductReceivedFromCustomer') : $langs->trans('ProductDeliveredToCustomer');
            }

            $origin_element = '';
            $origin_id = null;

            if($object->element == 'order_supplier') {
                $mouvement = ($novalidaction == 'deliveredtosupplier') ? 1 : 0; // 0 : Add / 1 : Delete
            } else {
                $mouvement = ($novalidaction == 'receiptofproduct') ? 0 : 1; // 0 : Add / 1 : Delete
            }

            $texttoadd = '';
            if(isset($object->array_options["options_savorders_history"]))
                $texttoadd = $object->array_options["options_savorders_history"];

            if($novalidaction == 'createdelivery' || $novalidaction == 'receivedfromsupplier') {
                $texttoadd .= '<br>';
            }

            $oneadded = 0;

            if(!$error)
            for ($i = 0; $i < $nblines; $i++) {
                if (empty($commande->lines[$i]->fk_product)) {
                    continue;
                }

                $objprod = new Product($db);
                $objprod->fetch($commande->lines[$i]->fk_product);

                if($objprod->type != Product::TYPE_PRODUCT) continue;

                $tmid = $commande->lines[$i]->fk_product;

                $warehouse  = $s && isset($s[$tmid]) && isset($s[$tmid]['warehouse']) ? $s[$tmid]['warehouse'] : 0;
                $qty        = $s && isset($s[$tmid]) && isset($s[$tmid]['qty']) ? $s[$tmid]['qty'] : $commande->lines[$i]->qty;

                if($novalidaction == 'receiptofproduct' || $novalidaction == 'deliveredtosupplier') {
                    $warehouse = $idwarehouse;
                }

                if(($novalidaction == 'createdelivery') && $warehouse <= 0) {
                    setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Warehouse")), null, 'errors');
                    $error++;
                }

                $txlabelmovement = '(SAV) '.$objprod->ref .': '. $labelmouve;

                // Determine price to use: cost price for product 483, PMP otherwise
                $price_to_use = ($objprod->id == 483) ? $objprod->cost_price : $objprod->pmp;

                if ($objprod->hasbatch()) {

                    $qty = ($qty > $commande->lines[$i]->qty) ? $commande->lines[$i]->qty : $qty;

                    if($qty)
                    for ($z=0; $z < $qty; $z++) { 
                        $batch = $s && isset($s[$tmid]) && isset($s[$tmid]['batch'][$z]) ? $s[$tmid]['batch'][$z] : '';

                        if(!$batch && $z == 0 && $qty > 0) { 
                            setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("batch_number")), null, 'errors');
                            $error++;
                            break;
                        }


                        if(!$error && ($batch || $qty == 0)) { 
                            if ($novalidaction == 'receiptofproduct' && $qty > 0) { 
                                $lot = new ProductLot($db);
                                $res = $lot->fetch(0, $objprod->id, $batch);
                                if ($res <= 0) {
                                    setEventMessages($langs->trans("BatchDoesNotExist", $batch), null, 'errors');
                                    $error++;
                                }
                            }

                            if (!$error && $batch && $novalidaction == 'createdelivery' && $qty > 0) { 

                                $lot = new ProductLot($db);
                                $res_lot_fetch = $lot->fetch(0, $objprod->id, $batch);
                                if ($res_lot_fetch <= 0) {
                                    setEventMessages($langs->trans("SerialNumberNotForProduct", $batch, $objprod->ref), null, 'errors');
                                    $error++;
                                }

                                if (!$error) { 
                                    $sql_stock_check = "SELECT SUM(pb.qty) as total_qty FROM " . MAIN_DB_PREFIX . "product_batch pb";
                                    $sql_stock_check .= " INNER JOIN " . MAIN_DB_PREFIX . "product_stock ps ON pb.fk_product_stock = ps.rowid";
                                    $sql_stock_check .= " WHERE ps.fk_product = " . (int)$objprod->id;
                                    $sql_stock_check .= " AND ps.fk_entrepot = " . (int)$warehouse;
                                    $sql_stock_check .= " AND pb.batch = '" . $db->escape($batch) . "';"; 
                                    
                                    $resql_stock_check = $db->query($sql_stock_check);
                                    if ($resql_stock_check) {
                                        $obj_stock = $db->fetch_object($resql_stock_check);
                                        if (!$obj_stock || $obj_stock->total_qty <= 0) {
                                            $warehouse_obj = new Entrepot($db); 
                                            $warehouse_ref = $warehouse; 
                                            if ($warehouse_obj->fetch($warehouse) > 0) {
                                                $warehouse_ref = $warehouse_obj->ref;
                                            }
                                            setEventMessages($langs->trans("SerialNumberNotInStockOrZeroQty", $batch, $warehouse_ref), null, 'errors');
                                            $error++;
                                        }
                                    } else {
                                        dol_syslog("SAVORDERS Error checking stock for batch: " . $db->error(), LOG_ERR);
                                        setEventMessages($langs->trans("ErrorCheckingSerialNumberStock", $batch), null, 'errors');
                                        $error++;
                                    }
                                }
                            }

                            if ($error) break; 

                            if (!$error && $qty > 0) { 
                                $result = $objprod->correct_stock_batch(
                                    $user,
                                    $warehouse,
                                    1, 
                                    $mouvement,
                                    $txlabelmovement, 
                                    $price_to_use, // Use determined price
                                    $d_eatby = '',
                                    $d_sellby = '',
                                    $batch,
                                    $inventorycode = '',
                                    $origin_element,
                                    $origin_id,
                                    $disablestockchangeforsubproduct = 0
                                ); 

                                if($result > 0) {
                                    $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod, $batch);
                                    $oneadded++;
                                } else {
                                    $error++;
                                }
                            }
                        }
                        if ($error) break; 
                    }

                } else { 
                    if(!$error && $qty >= 0) { 
                        if ($qty > 0) { 
                            $result = $objprod->correct_stock(
                                $user,
                                $warehouse,
                                $qty,
                                $mouvement,
                                $txlabelmovement,
                                $price_to_use, // Use determined price
                                $inventorycode = '',
                                $origin_element,
                                $origin_id,
                                $disablestockchangeforsubproduct = 0
                            ); 

                            if($result > 0) {
                                $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod);
                                $oneadded++;
                            } else {
                                $error++;
                            }
                        } else { 
                             $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod); 
                             $oneadded++; 
                        }
                    }
                }
                if ($error) break; 
            }

            if(!$error && $oneadded) {

                if($object->element == 'order_supplier') {
                    $savorders_status = ($novalidaction == 'deliveredtosupplier') ? $savorders::DELIVERED_SUPPLIER : $savorders::RECEIVED_SUPPLIER;
                } else {
                    if ($novalidaction == 'process_reimbursement') {
                        $savorders_status = $savorders::REIMBURSED;
                    } else {
                        $savorders_status = ($novalidaction == 'receiptofproduct') ? $savorders::RECIEVED_CUSTOMER : $savorders::DELIVERED_CUSTOMER;
                    }
                }

                $texttoadd = str_replace(['<span class="savorders_history_td">', '</span>'], ' ', $texttoadd);

                $extrafieldtxt = '<span class="savorders_history_td">';
                $extrafieldtxt .= $texttoadd;
                $extrafieldtxt .= '</span>';

                $object->array_options["options_savorders_history"] = $extrafieldtxt;
                $object->array_options["options_savorders_status"] = $savorders_status;
                if ($novalidaction == 'process_reimbursement') {
                    $object->array_options['options_facture_sav'] = GETPOST('facture_sav', 'int');
                }
                $result = $object->insertExtraFields();
                if(!$result) $error++;
            }

            if($error){
                setEventMessages($objprod->errors, $object->errors, 'errors');
                header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action='.$novalidaction);
            } else {
                if($oneadded)
                    setEventMessages($langs->trans("RecordCreatedSuccessfully"), null, 'mesgs');
                header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
                exit();
            }

        }
    }

    public function addLineHistoryToSavCommande(&$texttoadd, $novalidaction, $objprod = '', $batch = '')
    {
        global $langs, $savorders_date;

        $contenu = '- '.$savorders_date.': ';

        if($novalidaction == 'receiptofproduct' || $novalidaction == 'receivedfromsupplier') {
            $contenu .= $langs->trans("OrderSavRecieveProduct");
        }
        elseif($novalidaction == 'createdelivery' || $novalidaction == 'deliveredtosupplier') {
            $contenu .= $langs->trans("OrderSavDeliveryProduct");
        }
        elseif($novalidaction == 'process_reimbursement') {
            $contenu .= $langs->trans("OrderSavReimbursementProcessed");
        }

        $contenu .= ' <a target="_blank" href="'.dol_buildpath('/product/card.php?id='.$objprod->id, 1).'">';
        $contenu .= '<b>'.$objprod->ref.'</b>';
        $contenu .= '</a>';

        if($batch) {
            $contenu .=  ' NÂ° <b>'.$batch.'</b>';
        }

        $texttoadd .=  '<div class="savorders_history_txt " title="'.strip_tags($contenu).'">';
        $texttoadd .= $contenu;
        $texttoadd .=  '</div>';
    }

    public function addMoreActionsButtons($parameters, &$object, &$action = '')
    {
        global $db, $conf, $langs, $confirm, $user;

        $langs->load('admin');
        $langs->load('savorders@savorders');

    $allowed = $user->admin;

    if (!$allowed) {
        $sql = "
            SELECT 1 
            FROM ".MAIN_DB_PREFIX."usergroup_user u
            WHERE u.fk_user = ".(int)$user->id."
              AND u.fk_usergroup = 5
              AND u.entity = ".(int)$conf->entity;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $allowed = true;
        }
    }

    if (! $allowed) {
        return 0;
    }

        $form = new Form($db);

        $ngtmpdebug = GETPOST('ngtmpdebug', 'int');
        if($ngtmpdebug) {
            echo '<pre>';
            print_r($parameters);
            echo '</pre>';

            ini_set('display_startup_errors', 1);
            ini_set('display_errors', 1);
            error_reporting(-1);
        }
		
        if (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) {

            $s = GETPOST('savorders_data', 'array');
            $linktogo = $_SERVER["PHP_SELF"].'?id=' . $object->id;
            $tmparray = ['receiptofproduct', 'createdelivery', 'deliveredtosupplier', 'receivedfromsupplier', 'process_reimbursement'];

            if(in_array($action, $tmparray)) {
                ?>
                <script type="text/javascript">
                    $(document).ready(function() {
                        $('html, body').animate({
                            scrollTop: ($("#savorders_formconfirm").offset().top - 80)
                        }, 800);

                        function toggleBatchInput(qtyInput) {
                            var qty = parseInt($(qtyInput).val());
                            var batchContainer = $(qtyInput).closest('tr').find('.batch-input-container');
                            if (qty === 0) {
                                batchContainer.hide();
                            } else {
                                batchContainer.show();
                            }
                        }

                        $('input[name^="savorders_data"][name$="[qty]"]').each(function() {
                            toggleBatchInput(this);
                        });

                        $('input[name^="savorders_data"][name$="[qty]"]').on('input change', function() {
                            toggleBatchInput(this);
                        });
                    });
                </script>
                <?php

                if($object->element == 'order_supplier') {
                    $title = ($action == 'deliveredtosupplier') ? $langs->trans('ProductDeliveredToSupplier') : $langs->trans('ProductReceivedFromSupplier');
                } else {
                    if ($action == 'process_reimbursement') {
                        $title = $langs->trans('ProcessReimbursement');
                    } else {
                        $title = ($action == 'receiptofproduct') ? $langs->trans('ProductReceivedFromCustomer') : $langs->trans('ProductDeliveredToCustomer');
                    }
                }

                $formproduct = new FormProduct($db);
                $nblines = count($object->lines);
                
                print '<div class="tagtable paddingtopbottomonly centpercent noborderspacing savorders_formconfirm" id="savorders_formconfirm">';
                print_fiche_titre($title, '', $object->picto);

                $idwarehouse = isset($conf->global->SAVORDERS_ADMIN_IDWAREHOUSE) ? $conf->global->SAVORDERS_ADMIN_IDWAREHOUSE : 0;

                if($action == 'receiptofproduct' && $idwarehouse <= 0) {
                    $link = '<a href="'.dol_buildpath('savorders/admin/admin.php', 1).'" target="_blank">'.img_picto('', 'setup', '').' '.$langs->trans("Configuration").'</a>';
                    setEventMessages($langs->trans("ErrorFieldRequired", $langs->trans('SAV').' '.dol_htmlentitiesbr_decode($langs->trans('Warehouse'))).' '.$link, null, 'errors');
                    $error++;
                }

                print '<div class="tagtable paddingtopbottomonly centpercent noborderspacing savorders_formconfirm" id="savorders_formconfirm">';
                print '<form method="POST" action="'.$linktogo.'" class="notoptoleftroright">'."\n";
                print '<input type="hidden" name="action" value="'.$action.'_valid">'."\n";
                print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '').'">'."\n";

                $now = dol_now();

                print '<table class="valid centpercent">';
                    
                    print '<tr>';
                    if ($action == 'process_reimbursement') {
                        print '<tr><td colspan="2">'.$langs->trans("SelectInvoiceForReimbursement").'</td></tr>';
                        print '<tr>';
                        print '<td class="fieldrequired">'.$langs->trans("Invoice").'</td>';
                        print '<td>';
                        $factures = array(); 
                        if (method_exists($object, 'fetch_thirdparty')) {
                            $object->fetch_thirdparty();
                            $sql_invoices = "SELECT rowid, facnumber FROM ".MAIN_DB_PREFIX."facture WHERE fk_soc = ".$object->fk_soc." AND fk_statut > 0 ORDER BY facnumber DESC";
                            $resql_invoices = $db->query($sql_invoices);
                            if ($resql_invoices) {
                                while ($obj_inv = $db->fetch_object($resql_invoices)) {
                                    $factures[$obj_inv->rowid] = $obj_inv->facnumber;
                                }
                            }
                        }
                        print $form->selectarray('facture_sav', $factures, GETPOST('facture_sav', 'int'), 1);
                        print '</td>';
                        print '</tr>';
                    } else {
                        print '<tr>';
                        print '<td class="left"><b>'.$langs->trans("Product").'</b></td>';
                        print '<td class="left"><b>'.$langs->trans("batch_number").'</b></td>';
                        print '<td class="left"><b>'.$langs->trans("Qty").'</b></td>';

                        if($action == 'createdelivery' || $action == 'receivedfromsupplier') {
                            print '<td class="left">'.$langs->trans("Warehouse").'</td>';
                        }
                        print '</tr>';

                        for ($i = 0; $i < $nblines; $i++) {
                            if (empty($object->lines[$i]->fk_product)) {
                                continue;
                            }

                            $objprod = new Product($db);
                            $objprod->fetch($object->lines[$i]->fk_product);

                            if($objprod->type != Product::TYPE_PRODUCT) continue;

                            $hasbatch = $objprod->hasbatch();
                            $tmid = $object->lines[$i]->fk_product;
                            $warehouse  = $s && isset($s[$tmid]) && isset($s[$tmid]['warehouse']) ? $s[$tmid]['warehouse'] : 0;
                            $qty        = $s && isset($s[$tmid]) && isset($s[$tmid]['qty']) ? $s[$tmid]['qty'] : $object->lines[$i]->qty;

                            print '<tr class="oddeven_">';
                            print '<td class="left width300">'.$objprod->getNomUrl(1).'</td>';

                            print '<td class="left width300 batch-input-container">';
                            if($hasbatch) {
                                for ($z=0; $z < $object->lines[$i]->qty; $z++) { 
                                    $batch = $s && isset($s[$tmid]) && isset($s[$tmid]['batch'][$z]) ? $s[$tmid]['batch'][$z] : '';
                                    $display_batch_input = ($qty > $z) ? '' : 'style="display:none;"';
                                    print '<input type="text" class="flat width200 batch_input_field_'.$tmid.'" name="savorders_data['.$tmid.'][batch]['.$z.']" value="'.$batch.'" '.$display_batch_input.'/>';
                                }
                            } else {
                                print '-';
                            }
                            print '</td>';

                            $maxqty = ($hasbatch) ? 'max="'.$object->lines[$i]->qty.'"' : ''; 

                            print '<td class="left ">';
                            print '<input type="number" class="flat width50 savorder-qty-input" name="savorders_data['.$tmid.'][qty]" value="'.$qty.'" '.$maxqty.' min="0" step="any" data-product-id="'.$tmid.'" />';
                            print '</td>';

                            if($action == 'createdelivery' || $action == 'receivedfromsupplier') {
                                print '<td class="left selectWarehouses">';
                                $formproduct_sel = new FormProduct($db);
                                if (!isset($forcecombo)) {
                                    $forcecombo = 0;  
                                }
                                print $formproduct_sel->selectWarehouses($warehouse, 'savorders_data['.$tmid.'][warehouse]', '', 0, 0, 0, '', 0, $forcecombo);
                                print '</td>';
                            }
                            print '</tr>';
                        }
                    }

                    print '<tr><td colspan="'.($action == 'process_reimbursement' ? 2 : 4).'"></td></tr>';
                    print '<tr>';
                        print '<td colspan="'.($action == 'process_reimbursement' ? 2 : 4).'" class="center">';
                        print '<div class="savorders_dateaction">';
                        print '<b>'.$langs->trans('Date').'</b>: ';
                        print $form->selectDate('', 'savorders_date', 0, 0, 0, '', 1, 1);
                        print '</div>';
                        print '</td>';
                    print '</tr>';

                    print '<tr class="valid">';
                    print '<td class="valid center" colspan="'.($action == 'process_reimbursement' ? 2 : 4).'">';
                    print '<input type="submit" class="button valignmiddle" name="validate" value="'.$langs->trans("Validate").'">';
                    print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'">';
                    print '</td>';
                    print '</tr>'."\n";

                print '</table>';
                print "</form>\n";

                if (!empty($conf->use_javascript_ajax)) {
                    print '<script type="text/javascript">'."\n";
                    print '
                    $(document).ready(function () {
                        $(".confirmvalidatebutton").on("click", function() {
                            $(this).attr("disabled", "disabled");
                            setTimeout(function() { $(".confirmvalidatebutton").removeAttr("disabled"); }, 3000);
                            $(this).closest("form").submit();
                        });
                        $("td.selectWarehouses select").select2();

                        function updateBatchInputs(productId, currentQty) {
                            var originalQty = parseInt($(".savorder-qty-input[data-product-id=\'" + productId + "\']").attr("max")); 
                            $(".batch_input_field_" + productId).each(function(index) {
                                if (index < currentQty) {
                                    $(this).show();
                                } else {
                                    $(this).hide();
                                }
                            });
                        }

                        $(".savorder-qty-input").each(function() {
                            var productId = $(this).data("product-id");
                            var currentQty = parseInt($(this).val());
                            updateBatchInputs(productId, currentQty);
                        });

                        $(".savorder-qty-input").on("input change", function() {
                            var productId = $(this).data("product-id");
                            var currentQty = parseInt($(this).val());
                            if (isNaN(currentQty) || currentQty < 0) currentQty = 0; 

                            var batchContainer = $(this).closest("tr").find(".batch-input-container");
                            if (currentQty === 0) {
                                batchContainer.find("input[type=\'text\']").hide();
                            } else {
                                updateBatchInputs(productId, currentQty);
                            }
                        });
                    });
                    ';
                    print '</script>'."\n";
                }

                print '</div>';
                print '<br>';
                return 1;
            }
        }

        if (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) {
            if ($object->statut < 1) return 0;

            $nblines = count($object->lines);
            $savorders_sav = $object->array_options["options_savorders_sav"];
            $savorders_status = $object->array_options["options_savorders_status"];

            if($ngtmpdebug) {
                echo 'nblines : '.$nblines.'<br>';
                echo 'savorders_sav : '.$savorders_sav.'<br>';
                echo 'savorders_status : '.$savorders_status.'<br>';
                echo 'object->element : '.$object->element.'<br>';
            }

            if($savorders_sav && $nblines > 0) {
                print '<div class="inline-block divButAction">';
                if($object->element == 'order_supplier') {
                    if(empty($savorders_status)) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=deliveredtosupplier&token='.newToken().'">' . $langs->trans('ProductDeliveredToSupplier');
                        print '</a>';
                    } 
                    elseif($savorders_status == savorders::DELIVERED_SUPPLIER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=receivedfromsupplier&token='.newToken().'">' . $langs->trans('ProductReceivedFromSupplier');
                        print '</a>';
                    }
                } else {
                    if(empty($savorders_status)) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=receiptofproduct&token='.newToken().'">' . $langs->trans('ProductReceivedFromCustomer');
                        print '</a>';
                    } 
                    elseif($savorders_status == savorders::RECIEVED_CUSTOMER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=createdelivery&token='.newToken().'">' . $langs->trans('ProductDeliveredToCustomer');
                        print '</a>';
                    }
                    elseif($savorders_status == savorders::DELIVERED_CUSTOMER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status3" href="'.$linktogo.'&action=process_reimbursement&token='.newToken().'">' . $langs->trans('ProcessReimbursement');
                        print '</a>';
                    }
                }
                print '</div>';
            }
        }
        return 0;
    }

    public function printObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $conf;

        if (! in_array('ordercard', explode(':', $parameters['context']))) {
            return 0;
        }

        if (isset($parameters['optionals']['savorders_status'])) {
            $status = (int) $object->array_options['options_savorders_status'];
            if ($status === savorders::REIMBURSED) {
                $facId = (int) $object->array_options['options_facture_sav'];
                $label = $langs->trans('Reimbursed');  
                if ($facId > 0) {
                    $fac = new Facture($db);
                    if ($fac->fetch($facId) > 0) {
                        $amt = price($fac->total_ttc).' '.$langs->trans("Currency".$conf->currency);
                        $label = $langs->trans('ClientReimbursedAmount', $amt);
                    }
                }
                $parameters['optionals']['savorders_status']['value']
                    = '<span class="badge badge-status4">'.$label.'</span>';
                return 1;
            }
        }
        return 0;
    }
}

