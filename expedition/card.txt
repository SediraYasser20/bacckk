<?php
/* Copyright (C) 2003-2008	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2005-2016	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2005		Simon TOSSER			<simon@kornog-computing.com>
 * Copyright (C) 2005-2012	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2011-2017	Juanjo Menent			<jmenent@2byte.es>
 * Copyright (C) 2013       Florian Henry		  	<florian.henry@open-concept.pro>
 * Copyright (C) 2013       Marcos GarcÃƒÂ­a           <marcosgdf@gmail.com>
 * Copyright (C) 2014		Cedric GROSS			<c.gross@kreiz-it.fr>
 * Copyright (C) 2014-2017	Francis Appels			<francis.appels@yahoo.com>
 * Copyright (C) 2015		Claudio Aschieri		<c.aschieri@19.coop>
 * Copyright (C) 2016-2018	Ferran Marcet			<fmarcet@2byte.es>
 * Copyright (C) 2016		Yasser CarreÃƒÂ³n			<yacasia@gmail.com>
 * Copyright (C) 2018-2024  FrÃƒÂ©dÃƒÂ©ric France         <frederic.france@free.fr>
 * Copyright (C) 2020       Lenin Rivas         	<lenin@leninrivas.com>
 * Copyright (C) 2022       Josep LluÃƒÂ­s Amador      <joseplluis@lliuretic.cat>
 * Copyright (C) 2024		MDW						<mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/expedition/card.php
 *	\ingroup    expedition
 *	\brief      Card of a shipment
 */

// Load Dolibarr environment
require '../main.inc.php';

if (!function_exists('getBaseSerialNumberPart')) {
    /**
     * Extracts the base part of a serial number or MO reference string.
     * The base part is defined as the substring before the third hyphen,
     * or the whole string if fewer than three hyphens exist.
     *
     * @param string \$serial_string The input serial number or MO reference.
     * @return string The base part of the string.
     */
    function getBaseSerialNumberPart($serial_string) {
        $hyphen_count = 0;
        $cut_off_position = strlen($serial_string); // Default to full string

        for ($i = 0; $i < strlen($serial_string); $i++) {
            if ($serial_string[$i] == '-') {
                $hyphen_count++;
                if ($hyphen_count == 3) {
                    $cut_off_position = $i;
                    break;
                }
            }
        }
        return substr($serial_string, 0, $cut_off_position);
    }
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/sendings.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
if (isModEnabled("product") || isModEnabled("service")) {
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
}
if (isModEnabled("propal")) {
	require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
}
if (isModEnabled('productbatch')) {
	require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';
}
if (isModEnabled('project')) {
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("sendings", "companies", "bills", 'deliveries', 'orders', 'stocks', 'other', 'propal', 'productbatch'));

if (isModEnabled('incoterm')) {
	$langs->load('incoterm');
}
if (isModEnabled('productbatch')) {
	$langs->load('productbatch');
}

$origin = GETPOST('origin', 'alpha') ? GETPOST('origin', 'alpha') : 'expedition'; // Example: commande, propal
$origin_id = GETPOSTINT('id') ? GETPOSTINT('id') : '';
$id = $origin_id;
if (empty($origin_id)) {
	$origin_id  = GETPOSTINT('origin_id'); // Id of order or propal
}
if (empty($origin_id)) {
	$origin_id  = GETPOSTINT('object_id'); // Id of order or propal
}
$ref = GETPOST('ref', 'alpha');
$line_id = GETPOSTINT('lineid');
$facid = GETPOSTINT('facid');

$action		= GETPOST('action', 'alpha');
$confirm	= GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');

//PDF
$hidedetails = (GETPOSTINT('hidedetails') ? GETPOSTINT('hidedetails') : (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS') ? 1 : 0));
$hidedesc = (GETPOSTINT('hidedesc') ? GETPOSTINT('hidedesc') : (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') ? 1 : 0));
$hideref = (GETPOSTINT('hideref') ? GETPOSTINT('hideref') : (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_REF') ? 1 : 0));

$object = new Expedition($db);
$objectorder = new Commande($db);
$extrafields = new ExtraFields($db);

// fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);
$extrafields->fetch_name_optionals_label($object->table_element_line);
$extrafields->fetch_name_optionals_label($objectorder->table_element_line);

// Load object. Make an object->fetch
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be 'include', not 'include_once'

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
$hookmanager->initHooks(array('expeditioncard', 'globalcard'));

$date_delivery = dol_mktime(GETPOSTINT('date_deliveryhour'), GETPOSTINT('date_deliverymin'), 0, GETPOSTINT('date_deliverymonth'), GETPOSTINT('date_deliveryday'), GETPOSTINT('date_deliveryyear'));

$date_shipping = dol_mktime(GETPOSTINT('date_shippinghour'), GETPOSTINT('date_shippingmin'), 0, GETPOSTINT('date_shippingmonth'), GETPOSTINT('date_shippingday'), GETPOSTINT('date_shippingyear'));

if ($id > 0 || !empty($ref)) {
	$object->fetch($id, $ref);
	$object->fetch_thirdparty();
}

// Security check
$socid = '';
if ($user->socid) {
	$socid = $user->socid;
}

$result = restrictedArea($user, 'expedition', $object->id, '');

$permissiondellink = $user->hasRight('expedition', 'delivery', 'creer'); // Used by the include of actions_dellink.inc.php
$permissiontoadd = $user->hasRight('expedition', 'creer');

$upload_dir = $conf->expedition->dir_output.'/sending';

$editColspan = 0;
$objectsrc = null;
$typeobject = null;


/*
 * Actions
 */

$error = 0;
$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if ($cancel) {
		if ($origin && $origin_id > 0) {
			if ($origin == 'commande') {
				header("Location: ".DOL_URL_ROOT.'/expedition/shipment.php?id='.((int) $origin_id));
				exit;
			}
		} else {
			$action = '';
			$object->fetch($id); // show shipment also after canceling modification
		}
	}

	include DOL_DOCUMENT_ROOT.'/core/actions_dellink.inc.php'; // Must be 'include', not 'include_once'

	// Actions to build doc
	include DOL_DOCUMENT_ROOT.'/core/actions_builddoc.inc.php';

	// Back to draft
	if ($action == 'setdraft' && $permissiontoadd) {
		$object->fetch($id);
		$result = $object->setDraft($user, 0);
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		} else {
			header("Location: ".$_SERVER['PHP_SELF']."?id=".$object->id);
			exit;
		}
	}
	// Reopen
	if ($action == 'reopen' && $permissiontoadd) {
		$object->fetch($id);
		$result = $object->reOpen();
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		} else {
			header("Location: ".$_SERVER['PHP_SELF']."?id=".$object->id);
			exit;
		}
	}

	// Set incoterm
	if ($action == 'set_incoterms' && isModEnabled('incoterm') && $permissiontoadd) {
		$result = $object->setIncoterms(GETPOSTINT('incoterm_id'), GETPOST('location_incoterms'));
	}

	if ($action == 'setref_customer' && $permissiontoadd) {
		$result = $object->fetch($id);
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}

		$result = $object->setValueFrom('ref_customer', GETPOST('ref_customer', 'alpha'), '', null, 'text', '', $user, 'SHIPMENT_MODIFY');
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
			$action = 'editref_customer';
		} else {
			header("Location: ".$_SERVER['PHP_SELF']."?id=".$object->id);
			exit;
		}
	}

	if ($action == 'update_extras' && $permissiontoadd) {
		$object->oldcopy = dol_clone($object, 2);
		$attribute_name = GETPOST('attribute', 'restricthtml');

		// Fill array 'array_options' with data from update form
		$ret = $extrafields->setOptionalsFromPost(null, $object, $attribute_name);
		if ($ret < 0) {
			$error++;
		}

		if (!$error) {
			// Actions on extra fields
			$result = $object->updateExtraField($attribute_name, 'SHIPMENT_MODIFY');
			if ($result < 0) {
				setEventMessages($object->error, $object->errors, 'errors');
				$error++;
			}
		}

		if ($error) {
			$action = 'edit_extras';
		}
	}

	// Create shipment
	// Create shipment
// Create shipment
if ($action == 'add' && $permissiontoadd) {
    $db->begin();

    $object->note = GETPOST('note', 'restricthtml');
    $object->note_private = GETPOST('note', 'restricthtml');
    
    // Ensure $origin is correctly sourced
    $origin_for_add = GETPOST('origin', 'alpha');
    if (empty($origin_for_add)) {
        setEventMessages($langs->trans("ErrorMissingOriginForShipmentCreation"), null, 'errors');
        $error++;
    } else {
        $object->origin = $origin_for_add;
    }

    $object->origin_id = GETPOSTINT('origin_id');
    $object->fk_project = GETPOSTINT('projectid');
    $object->weight = GETPOSTINT('weight') == '' ? "NULL" : GETPOSTINT('weight');
    $object->sizeH = GETPOSTINT('sizeH') == '' ? "NULL" : GETPOSTINT('sizeH');
    $object->sizeW = GETPOSTINT('sizeW') == '' ? "NULL" : GETPOSTINT('sizeW');
    $object->sizeS = GETPOSTINT('sizeS') == '' ? "NULL" : GETPOSTINT('sizeS');
    $object->size_units = GETPOSTINT('size_units');
    $object->weight_units = GETPOSTINT('weight_units');

    $product_for_mo_details = new Product($db);

    // Process origin document
    if (!$error) {
        $classname = ucfirst($object->origin);
        if (class_exists($classname)) {
            $objectsrc = new $classname($db);
            '@phan-var-force Facture|Commande $objectsrc';
            if (!$objectsrc->fetch($object->origin_id)) {
                setEventMessages($langs->trans("ErrorFailedToFetchOriginObject", $object->origin, $object->origin_id), null, 'errors');
                $error++;
            }
        } else {
            setEventMessages($langs->trans("ErrorUnknownOriginClass", $classname), null, 'errors');
            $error++;
        }
    }
    
    if (!$error) {
        $object->socid = $objectsrc->socid;
        $object->ref_customer = GETPOST('ref_customer', 'alpha');
        $object->model_pdf = GETPOST('model');
        $object->date_delivery = $date_delivery;
        $object->date_shipping = $date_shipping;
        $object->fk_delivery_address = $objectsrc->fk_delivery_address;
        $object->shipping_method_id = GETPOSTINT('shipping_method_id');
        $object->tracking_number = GETPOST('tracking_number', 'alpha');
        $object->note_private = GETPOST('note_private', 'restricthtml');
        $object->note_public = GETPOST('note_public', 'restricthtml');
        $object->fk_incoterms = GETPOSTINT('incoterm_id');
        $object->location_incoterms = GETPOST('location_incoterms', 'alpha');

        $batch_line = array();
        $stockLine = array();
        $array_options = array();

        $num = count($objectsrc->lines);
        $totalqty = 0;
        $product_batch_used_for_serial_check = array();

        // Process each order line
        for ($i = 0; $i < $num; $i++) {
            $current_order_line = $objectsrc->lines[$i];
            $idl_name = "idl".$i; 
            $original_line_id = GETPOSTINT($idl_name); 

            $sub_qty_details = array(); 
            $subtotal_qty_for_line = 0; 

            $qty_input_prefix = "qtyl".$i;        
            $batch_input_prefix = "batchl".$i;    
            $stock_loc_input_prefix = "ent1".$i;  

            $effective_fk_product_for_line = $current_order_line->fk_product;
            $product_management_mode = 0; 
            $mo_product_id_override = 483;
            $product_ref_for_error_msg = $current_order_line->ref;

            // FIXED: Correct MO detection syntax
            $is_mo_line = false;
            if (empty($current_order_line->fk_product)) {
                $is_mo_line = (strpos($current_order_line->description, 'Costum-PC') === 0) && 
                              (strpos($current_order_line->description, '(Fabrication)') !== false);
            }

            if ($is_mo_line) {
                if ($product_for_mo_details->fetch($mo_product_id_override) > 0) {
                    $effective_fk_product_for_line = $mo_product_id_override;
                    $product_management_mode = $product_for_mo_details->status_batch;
                    $product_ref_for_error_msg = $product_for_mo_details->ref;
                } else {
                    setEventMessages("Error: Product with rowid ".$mo_product_id_override." for MO line processing not found.", null, 'errors');
                    $error++; 
                }
            } elseif (!empty($current_order_line->fk_product)) {
                $temp_regular_prod = new Product($db);
                if ($temp_regular_prod->fetch($current_order_line->fk_product) > 0) {
                    $product_management_mode = $temp_regular_prod->status_batch;
                    $product_ref_for_error_msg = $temp_regular_prod->ref;
                } else {
                    setEventMessages("Error: Product ".$current_order_line->fk_product." (Order Line ID: ".$current_order_line->id.") not found during quantity processing.", null, 'errors');
                    $error++;
                }
            }
            
            if ($error) break; 

            // Batch/Serial managed products
            if (isModEnabled('productbatch') && $product_management_mode > 0) {
                $k = 0; 
                $current_batch_field_name = $batch_input_prefix."_".$k; 
                $current_qty_field_name = $qty_input_prefix."_".$k;     

                if (GETPOSTISSET($current_batch_field_name)) { 
                    while (GETPOSTISSET($current_batch_field_name)) {
                        $qty_val = price2num(GETPOST($current_qty_field_name, 'alpha'), 'MS');
                        $batch_id_val = GETPOSTINT($current_batch_field_name);

                        if ($qty_val > 0) {
                            if ($product_management_mode == 2) {
                                if ($qty_val > 1) {
                                    setEventMessages($langs->trans("TooManyQtyForSerialNumber", $product_ref_for_error_msg), null, 'errors');
                                    $error++; break 2; 
                                }
                                // Create unique serial key per product to avoid conflicts
                                $serial_key = $effective_fk_product_for_line . '_' . $batch_id_val;
                                if (in_array($serial_key, $product_batch_used_for_serial_check)) {
                                    setEventMessages($langs->trans("SerialAlreadyUsed", $product_ref_for_error_msg), null, 'errors');
                                    $error++; break 2; 
                                }
                                $product_batch_used_for_serial_check[] = $serial_key;

                                // Custom validation for Product 483 linked to an MO
                                // $is_mo_line is determined before this loop, based on $current_order_line->fk_product being empty and description matching.
                                // $current_order_line is $objectsrc->lines[$i]
                                // $effective_fk_product_for_line is 483 if $is_mo_line is true.
                                if ($is_mo_line && $effective_fk_product_for_line == 483) {
                                    $mo_ref_from_db = '';
                                    // Fetch the MO reference using fk_mrp_mo from the order line
                                    if (!empty($current_order_line->fk_mrp_mo)) {
                                        $sql_mo_ref = "SELECT ref FROM ".MAIN_DB_PREFIX."mrp_mo WHERE rowid = ".((int) $current_order_line->fk_mrp_mo);
                                        $resql_mo_ref = $db->query($sql_mo_ref);
                                        if ($resql_mo_ref) {
                                            if ($db->num_rows($resql_mo_ref) > 0) {
                                                $obj_mo_ref = $db->fetch_object($resql_mo_ref);
                                                $mo_ref_from_db = $obj_mo_ref->ref;
                                            } else {
                                                setEventMessages($langs->trans("ErrorMORecordNotFoundForFK", $current_order_line->fk_mrp_mo), null, 'errors');
                                                $error++; break 2;
                                            }
                                        } else {
                                            setEventMessages($langs->trans("ErrorFailedToFetchMORefDB", $db->lasterror()), null, 'errors');
                                            $error++; break 2;
                                        }
                                    } else {
                                        // Fallback: Try to parse from description if fk_mrp_mo is not set
                                        // This part assumes $current_order_line->description is the original description of the MO line
                                        if (preg_match('/^(Costum-PC\S+)/', trim($current_order_line->description), $matches_desc_mo)) {
                                            $mo_ref_from_db = $matches_desc_mo[1];
                                        } else {
                                            // If fk_mrp_mo is not set and description doesn't match, it's an error for Product 483 MO lines.
                                            setEventMessages($langs->trans("ErrorMORefLinkOrDescMissing", $current_order_line->id), null, 'errors');
                                            $error++; break 2;
                                        }
                                    }

                                    $productbatch_entry = new Productbatch($db);
                                    // $batch_id_val is the rowid from llx_product_batch table
                                    if ($batch_id_val > 0 && $productbatch_entry->fetch($batch_id_val) > 0) {
                                        $entered_serial = $productbatch_entry->batch; // This is the actual serial string

                                        // New validation using getBaseSerialNumberPart
                                        $base_mo_ref = getBaseSerialNumberPart($mo_ref_from_db);
                                        $base_entered_serial = getBaseSerialNumberPart($entered_serial);

                                        if ($base_mo_ref !== $base_entered_serial) {
                                            setEventMessages($langs->trans("ErrorSerialBaseDoesNotMatchMOBase", $entered_serial, $mo_ref_from_db), null, 'errors');
                                            $error++; break 2;
                                        } else {
                                            // Base parts match. Now, additionally check the suffix for serials that are expected to have one.
                                            if (strlen($entered_serial) > strlen($base_entered_serial)) {
                                                $suffix_part = substr($entered_serial, strlen($base_entered_serial));
                                                // Suffix must be like "-1", "-23", etc. (a hyphen followed by a positive integer)
                                                // preg_match('/^-([1-9]\d*)$/', $suffix_part, $matches_suffix)
                                                // Ensure $matches_suffix[1] is a positive integer. Simply is_numeric is not enough (e.g. -0, -007)
                                                if (!preg_match('/^-([1-9]\d*)$/', $suffix_part)) {
                                                    setEventMessages($langs->trans("ErrorSerialSuffixInvalidFormat", $entered_serial, $base_entered_serial), null, 'errors');
                                                    $error++; break 2;
                                                }
                                            }
                                            // If strlen($entered_serial) == strlen($base_entered_serial), an exact match of bases is accepted.
                                        }
                                    } else {
                                        setEventMessages($langs->trans("ErrorFailedToFetchBatchDetailsForID", $batch_id_val), null, 'errors');
                                        $error++; break 2;
                                    }
                                }
                            }
                            $sub_qty_details[$k]['q'] = $qty_val;
                            $sub_qty_details[$k]['id_batch'] = $batch_id_val; // This is llx_product_batch.rowid
                            $subtotal_qty_for_line += $qty_val;
                        }
                        $k++;
                        $current_batch_field_name = $batch_input_prefix."_".$k;
                        $current_qty_field_name = $qty_input_prefix."_".$k;
                    }
                    if (!$error) { 
                        $batch_line[$i]['detail'] = $sub_qty_details; 
                        $batch_line[$i]['qty'] = $subtotal_qty_for_line; 
                        $batch_line[$i]['ix_l'] = $original_line_id;
                        // Store the effective product ID for later use
                        $batch_line[$i]['fk_product'] = $effective_fk_product_for_line;
                    }
                }
            }
            // Multiple stock locations
            elseif ($effective_fk_product_for_line > 0 && isModEnabled('stock') && GETPOSTISSET($stock_loc_input_prefix."_0")) {
                $k = 0;
                $current_stock_loc_field_name = $stock_loc_input_prefix."_".$k;
                $current_qty_field_name = $qty_input_prefix."_".$k;
                while (GETPOSTISSET($current_stock_loc_field_name)) {
                    $qty_val = price2num(GETPOST($current_qty_field_name, 'alpha'), 'MS');
                    if ($qty_val > 0) {
                        $stockLine[$i][$k]['qty'] = $qty_val;
                        $stockLine[$i][$k]['warehouse_id'] = GETPOSTINT($current_stock_loc_field_name);
                        $stockLine[$i][$k]['ix_l'] = $original_line_id;
                        // Store the effective product ID for later use
                        $stockLine[$i][$k]['fk_product'] = $effective_fk_product_for_line;
                        $subtotal_qty_for_line += $qty_val;
                    }
                    $k++;
                    $current_stock_loc_field_name = $stock_loc_input_prefix."_".$k;
                    $current_qty_field_name = $qty_input_prefix."_".$k;
                }
            }
            // Simple products
            else {
                $plain_qty_field_name = $qty_input_prefix; 
                $qty_val = price2num(GETPOST($plain_qty_field_name, 'alpha'), 'MS');
                if ($qty_val > 0) {
                    $subtotal_qty_for_line = $qty_val;
                }
            }

            if ($error) break; 
            $totalqty += $subtotal_qty_for_line; 

            if (getDolGlobalInt("MAIN_DONT_SHIP_MORE_THAN_ORDERED") && $subtotal_qty_for_line > $current_order_line->qty) {
                setEventMessages($langs->trans("ErrorTooMuchShipped", ($i + 1)." - ".$product_ref_for_error_msg), null, 'errors');
                $error++;
            }

            $array_options[$i] = $extrafields->getOptionalsFromPost($object->table_element_line, $i);
            if (isset($extrafields->attributes[$object->table_element_line]['label']) && is_array($extrafields->attributes[$object->table_element_line]['label'])) {
                foreach ($extrafields->attributes[$object->table_element_line]['label'] as $key_extra => $value_extra) {
                    unset($_POST["options_".$key_extra]);
                }
            }
            if ($error) break; 
        }

        // Add shipment lines
        if (($totalqty > 0 || getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS')) && !$error) {
            for ($idx_add = 0; $idx_add < $num; $idx_add++) {
                $current_order_line_for_add = $objectsrc->lines[$idx_add];
                $original_line_id_for_add = GETPOSTINT("idl".$idx_add);

                // Determine the correct product ID for this line
                $is_mo_line_for_add = false;
                if (empty($current_order_line_for_add->fk_product)) {
                    $is_mo_line_for_add = (strpos($current_order_line_for_add->description, 'Costum-PC') === 0) && 
                                         (strpos($current_order_line_for_add->description, '(Fabrication)') !== false);
                }
                $fk_product_for_add = $is_mo_line_for_add ? 483 : $current_order_line_for_add->fk_product;

                // Handle batch lines
                if (isset($batch_line[$idx_add]) && is_array($batch_line[$idx_add]) && !empty($batch_line[$idx_add]['detail'])) {
                    if ($batch_line[$idx_add]['qty'] > 0 || ($batch_line[$idx_add]['qty'] == 0 && getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS'))) {
                        // Use the stored product ID from batch processing
                        $fk_product_for_batch = isset($batch_line[$idx_add]['fk_product']) ? $batch_line[$idx_add]['fk_product'] : $fk_product_for_add;
                        
                        $ret = $object->addline_batch($batch_line[$idx_add], $array_options[$idx_add], $fk_product_for_batch);
                        if ($ret < 0) {
                            setEventMessages($object->error, $object->errors, 'errors');
                            $error++;
                        }
                    }
                } 
                // Handle stock lines
                elseif (isset($stockLine[$idx_add])) { 
                    $nbstockline = count($stockLine[$idx_add]);
                    for ($j = 0; $j < $nbstockline; $j++) {
                        if ($stockLine[$idx_add][$j]['qty'] > 0 || ($stockLine[$idx_add][$j]['qty'] == 0 && getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS'))) {
                            // Use the stored product ID from stock processing
                            $fk_product_for_stock = isset($stockLine[$idx_add][$j]['fk_product']) ? $stockLine[$idx_add][$j]['fk_product'] : $fk_product_for_add;
                            
                            $ret = $object->addline($stockLine[$idx_add][$j]['warehouse_id'], $stockLine[$idx_add][$j]['ix_l'], $stockLine[$idx_add][$j]['qty'], $array_options[$idx_add], $fk_product_for_stock);
                            if ($ret < 0) {
                                setEventMessages($object->error, $object->errors, 'errors');
                                $error++;
                            }
                        }
                    }
                } 
                // Handle simple lines
                else { 
                    $qty_val_for_add = price2num(GETPOST("qtyl".$idx_add, 'alpha'), 'MS');
                    if ($qty_val_for_add > 0 || getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS')) {
                        $ent_field_name = "entl".$idx_add;
                        $entrepot_id_for_add = is_numeric(GETPOSTINT($ent_field_name)) ? GETPOSTINT($ent_field_name) : GETPOSTINT('entrepot_id');
                        if ($entrepot_id_for_add < 0) $entrepot_id_for_add = '';

                        // For non-product lines (services, etc.), set warehouse to 0
                        if (!($current_order_line_for_add->fk_product > 0) && !$is_mo_line_for_add) {
                            $entrepot_id_for_add = 0;
                        }
                        
                        $ret = $object->addline($entrepot_id_for_add, $original_line_id_for_add, $qty_val_for_add, $array_options[$idx_add], $fk_product_for_add);
                        if ($ret < 0) {
                            setEventMessages($object->error, $object->errors, 'errors');
                            $error++;
                        }
                    }
                }
                if ($error) break;
            }

            if (!$error) {
                $ret = $extrafields->setOptionalsFromPost(null, $object);
                if ($ret < 0) $error++;
            }

            // FIX: Improved MO detection and override with database update
            if (!$error) {
                $mo_product_id_override_final = 483;
                $product_for_mo_shipment_final = new Product($db);

                foreach ($object->lines as $key_ship_line => $shipment_line_to_be) {
                    $original_order_line_id_final = $shipment_line_to_be->origin_line_id;
                    $corresponding_order_line_final = null;
                    foreach ($objectsrc->lines as $order_line_in_source_final) {
                        if ($order_line_in_source_final->id == $original_order_line_id_final) {
                            $corresponding_order_line_final = $order_line_in_source_final;
                            break;
                        }
                    }
                    if ($corresponding_order_line_final) {
                        $is_mo_line_final = false;
                        if (empty($corresponding_order_line_final->fk_product)) {
                            $is_mo_line_final = (strpos($corresponding_order_line_final->description, 'Costum-PC') === 0) && 
                                               (strpos($corresponding_order_line_final->description, '(Fabrication)') !== false);
                        }
                        if ($is_mo_line_final) {
                            if ($product_for_mo_shipment_final->fetch($mo_product_id_override_final) > 0) {
                                // Update in-memory object
                                $object->lines[$key_ship_line]->fk_product = $mo_product_id_override_final;
                                $object->lines[$key_ship_line]->fk_product_type = $product_for_mo_shipment_final->type;
                                $object->lines[$key_ship_line]->ref = $product_for_mo_shipment_final->ref;
                                $object->lines[$key_ship_line]->product_label = $product_for_mo_shipment_final->label;
                                $object->lines[$key_ship_line]->description = $product_for_mo_shipment_final->label;

                                if (getDolGlobalString('MAIN_PRODUCT_USE_UNITS') && !empty($product_for_mo_shipment_final->fk_unit)) {
                                    $object->lines[$key_ship_line]->fk_unit = $product_for_mo_shipment_final->fk_unit;
                                }
                                $object->lines[$key_ship_line]->weight = $product_for_mo_shipment_final->weight;
                                $object->lines[$key_ship_line]->weight_units = $product_for_mo_shipment_final->weight_units;
                                $object->lines[$key_ship_line]->volume = $product_for_mo_shipment_final->volume;
                                $object->lines[$key_ship_line]->volume_units = $product_for_mo_shipment_final->volume_units;
                                $object->lines[$key_ship_line]->product_tobatch = $product_for_mo_shipment_final->status_batch;
                                $object->lines[$key_ship_line]->length = $product_for_mo_shipment_final->length;
                                $object->lines[$key_ship_line]->length_units = $product_for_mo_shipment_final->length_units;
                                $object->lines[$key_ship_line]->width = $product_for_mo_shipment_final->width;
                                $object->lines[$key_ship_line]->width_units = $product_for_mo_shipment_final->width_units;
                                $object->lines[$key_ship_line]->height = $product_for_mo_shipment_final->height;
                                $object->lines[$key_ship_line]->height_units = $product_for_mo_shipment_final->height_units;
                                $object->lines[$key_ship_line]->surface = $product_for_mo_shipment_final->surface;
                                $object->lines[$key_ship_line]->surface_units = $product_for_mo_shipment_final->surface_units;

                                // Update the line in the database
                                $result = $object->lines[$key_ship_line]->update($user);
                                if ($result < 0) {
                                    $error++;
                                    setEventMessages($object->lines[$key_ship_line]->error, $object->lines[$key_ship_line]->errors, 'errors');
                                }
                            } else {
                                setEventMessages("Error: Product with rowid ".$mo_product_id_override_final." not found for final MO shipment line update.", null, 'errors');
                                $error++; break;
                            }
                        }
                    }
                }
            }

            if (!$error) {
                $ret = $object->create($user);
                if ($ret <= 0) {
                    setEventMessages($object->error, $object->errors, 'errors');
                    $error++;
                }
            }
        } elseif (!$error) {
            $ret = $extrafields->setOptionalsFromPost(null, $object);

            if (empty($conf->global->MAIN_DISABLE_DEBUG_BAR) || !empty($conf->global->MAIN_LOG_DEBUGGING)) {
                dol_syslog("Expedition card.php: 'Field Required' error triggered. totalqty=" . $totalqty . ". SHIPMENT_GETS_ALL_ORDER_PRODUCTS=" . (getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS') ? '1' : '0') . ". POST data: " . print_r($_POST, true), LOG_DEBUG);
            }
            $labelfieldmissing = $langs->transnoentitiesnoconv("QtyToShip");
            if (isModEnabled('stock')) {
                $labelfieldmissing .= '/'.$langs->transnoentitiesnoconv("Warehouse");
                if (isModEnabled('productbatch')) { 
                     $labelfieldmissing .= '/'.$langs->transnoentitiesnoconv("Batch");
                }
            }
            setEventMessages($langs->trans("ErrorFieldRequired", $labelfieldmissing), null, 'errors');
            $error++;
        }
    }

    if (!$error) {
        $db->commit();
        header("Location: ".$_SERVER['PHP_SELF']."?id=".$object->id);
        exit;
    } else {
        $db->rollback();
        $action = 'create'; 
    }
}elseif ($action == 'create_delivery' && getDolGlobalInt('MAIN_SUBMODULE_DELIVERY') && $user->hasRight('expedition', 'delivery', 'creer')) {
		// Build a receiving receipt
		$db->begin();

		$result = $object->create_delivery($user);
		if ($result > 0) {
			$db->commit();

			header("Location: ".DOL_URL_ROOT.'/delivery/card.php?action=create_delivery&token='.newToken().'&id='.$result);
			exit;
		} else {
			$db->rollback();

			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action == 'confirm_valid' && $confirm == 'yes' && ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('expedition', 'creer'))
		|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('expedition', 'shipping_advance', 'validate')))
	) {
		$object->fetch_thirdparty();

		$result = $object->valid($user);

		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		} else {
			// Define output language
			if (!getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
				$outputlangs = $langs;
				$newlang = '';
				if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang) && GETPOST('lang_id', 'aZ09')) {
					$newlang = GETPOST('lang_id', 'aZ09');
				}
				if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang)) {
					$newlang = $object->thirdparty->default_lang;
				}
				if (!empty($newlang)) {
					$outputlangs = new Translate("", $conf);
					$outputlangs->setDefaultLang($newlang);
				}
				$model = $object->model_pdf;
				$ret = $object->fetch($id); // Reload to get new records

				$result = $object->generateDocument($model, $outputlangs, $hidedetails, $hidedesc, $hideref);
				if ($result < 0) {
					dol_print_error($db, $object->error, $object->errors);
				}
			}
		}
	} elseif ($action == 'confirm_cancel' && $confirm == 'yes' && $user->hasRight('expedition', 'supprimer')) {
		$also_update_stock = (GETPOST('alsoUpdateStock', 'alpha') ? 1 : 0);
		$result = $object->cancel(0, $also_update_stock);
		if ($result > 0) {
			$result = $object->setStatut(-1);
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action == 'confirm_delete' && $confirm == 'yes' && $user->hasRight('expedition', 'supprimer')) {
		$also_update_stock = (GETPOST('alsoUpdateStock', 'alpha') ? 1 : 0);
		$result = $object->delete($user, 0, $also_update_stock);
		if ($result > 0) {
			header("Location: ".DOL_URL_ROOT.'/expedition/index.php');
			exit;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
		// TODO add alternative status
		//} elseif ($action == 'reopen' && ($user->hasRight('expedition', 'creer') || $user->hasRight('expedition', 'shipping_advance', 'validate')))
		//{
		//	$result = $object->setStatut(0);
		//	if ($result < 0)
		//	{
		//		setEventMessages($object->error, $object->errors, 'errors');
		//	}
		//}
	} elseif ($action == 'setdate_livraison' && $user->hasRight('expedition', 'creer')) {
		$datedelivery = dol_mktime(GETPOSTINT('liv_hour'), GETPOSTINT('liv_min'), 0, GETPOSTINT('liv_month'), GETPOSTINT('liv_day'), GETPOSTINT('liv_year'));

		$object->fetch($id);
		$result = $object->setDeliveryDate($user, $datedelivery);
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action == 'setdate_shipping' && $user->hasRight('expedition', 'creer')) {
		$dateshipping = dol_mktime(GETPOSTINT('ship_hour'), GETPOSTINT('ship_min'), 0, GETPOSTINT('ship_month'), GETPOSTINT('ship_day'), GETPOSTINT('ship_year'));

		$object->fetch($id);
		$result = $object->setShippingDate($user, $dateshipping);
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif (in_array($action, array('settracking_number', 'settracking_url', 'settrueWeight', 'settrueWidth', 'settrueHeight', 'settrueDepth', 'setshipping_method_id')) && $user->hasRight('expedition', 'creer')) {
		// Action update
		$error = 0;

		if ($action == 'settracking_number') {
			$object->tracking_number = trim(GETPOST('tracking_number', 'alpha'));
		}
		if ($action == 'settracking_url') {
			$object->tracking_url = trim(GETPOST('tracking_url', 'restricthtml'));
		}
		if ($action == 'settrueWeight') {
			$object->trueWeight = GETPOSTINT('trueWeight');
			$object->weight_units = GETPOSTINT('weight_units');
		}
		if ($action == 'settrueWidth') {
			$object->trueWidth = GETPOSTINT('trueWidth');
		}
		if ($action == 'settrueHeight') {
			$object->trueHeight = GETPOSTINT('trueHeight');
			$object->size_units = GETPOSTINT('size_units');
		}
		if ($action == 'settrueDepth') {
			$object->trueDepth = GETPOSTINT('trueDepth');
		}
		if ($action == 'setshipping_method_id') {
			$object->shipping_method_id = GETPOSTINT('shipping_method_id');
		}

		if (!$error) {
			if ($object->update($user) >= 0) {
				header("Location: card.php?id=".$object->id);
				exit;
			}
			setEventMessages($object->error, $object->errors, 'errors');
		}

		$action = "";
	} elseif ($action == 'classifybilled' && $permissiontoadd) {
		$object->fetch($id);
		$result = $object->setBilled();
		if ($result >= 0) {
			header('Location: '.$_SERVER["PHP_SELF"].'?id='.$object->id);
			exit();
		}
		setEventMessages($object->error, $object->errors, 'errors');
	} elseif ($action == 'classifyclosed' && $permissiontoadd) {
		$object->fetch($id);
		$result = $object->setClosed();
		if ($result >= 0) {
			header('Location: '.$_SERVER["PHP_SELF"].'?id='.$object->id);
			exit();
		}
		setEventMessages($object->error, $object->errors, 'errors');
	} elseif ($action == 'deleteline' && !empty($line_id) && $permissiontoadd) {
		// delete a line
		$object->fetch($id);
		$lines = $object->lines;
		$line = new ExpeditionLigne($db);
		$line->fk_expedition = $object->id;

		$num_prod = count($lines);
		for ($i = 0; $i < $num_prod; $i++) {
			if ($lines[$i]->id == $line_id) {
				if (count($lines[$i]->details_entrepot) > 1) {
					// delete multi warehouse lines
					foreach ($lines[$i]->details_entrepot as $details_entrepot) {
						$line->id = $details_entrepot->line_id;
						if (!$error && $line->delete($user) < 0) {
							$error++;
						}
					}
				} else {
					// delete single warehouse line
					$line->id = $line_id;
					if (!$error && $line->delete($user) < 0) {
						$error++;
					}
				}
			}
			unset($_POST["lineid"]);
		}

		if (!$error) {
			header('Location: '.$_SERVER["PHP_SELF"].'?id='.$object->id);
			exit();
		} else {
			setEventMessages($line->error, $line->errors, 'errors');
		}
	} elseif ($action == 'updateline' && $permissiontoadd && GETPOST('save')) {
		// Update a line
		// Clean parameters
		$qty = 0;
		$entrepot_id = 0;
		$batch_id = 0;

		$lines = $object->lines;
		$num_prod = count($lines);
		for ($i = 0; $i < $num_prod; $i++) {
			if ($lines[$i]->id == $line_id) {		// we have found line to update
				$update_done = false;
				$line = new ExpeditionLigne($db);
				$line->fk_expedition = $object->id;

				// Extrafields Lines
				$line->array_options = $extrafields->getOptionalsFromPost($object->table_element_line);
				// Unset extrafield POST Data
				if (is_array($extrafields->attributes[$object->table_element_line]['label'])) {
					foreach ($extrafields->attributes[$object->table_element_line]['label'] as $key => $value) {
						unset($_POST["options_".$key]);
					}
				}
				$line->fk_product = $lines[$i]->fk_product;
				if (is_array($lines[$i]->detail_batch) && count($lines[$i]->detail_batch) > 0) {
					// line with lot
					foreach ($lines[$i]->detail_batch as $detail_batch) {
						$lotStock = new Productbatch($db);
						$batch = "batchl".$detail_batch->fk_expeditiondet."_".$detail_batch->fk_origin_stock;
						$qty = "qtyl".$detail_batch->fk_expeditiondet.'_'.$detail_batch->id;
						$batch_id = GETPOSTINT($batch);
						$batch_qty = GETPOSTFLOAT($qty);
						if (!empty($batch_id)) {
							if ($lotStock->fetch($batch_id) > 0 && $line->fetch($detail_batch->fk_expeditiondet) > 0) {	// $line is ExpeditionLine
								if ($lines[$i]->entrepot_id != 0) {
									// allow update line entrepot_id if not multi warehouse shipping
									$line->entrepot_id = $lotStock->warehouseid;
								}

								// detail_batch can be an object with keys, or an array of ExpeditionLineBatch
								if (empty($line->detail_batch)) {
									$line->detail_batch = new stdClass();
								}

								$line->detail_batch->fk_origin_stock = $batch_id;
								$line->detail_batch->batch = $lotStock->batch;
								$line->detail_batch->id = $detail_batch->id;
								$line->detail_batch->entrepot_id = $lotStock->warehouseid;
								$line->detail_batch->qty = $batch_qty;
								if ($line->update($user) < 0) {
									setEventMessages($line->error, $line->errors, 'errors');
									$error++;
								} else {
									$update_done = true;
								}
							} else {
								setEventMessages($lotStock->error, $lotStock->errors, 'errors');
								$error++;
							}
						}
						unset($_POST[$batch]);
						unset($_POST[$qty]);
					}
					// add new batch
					$lotStock = new Productbatch($db);
					$batch = "batchl".$line_id."_0";
					$qty = "qtyl".$line_id."_0";
					$batch_id = GETPOSTINT($batch);
					$batch_qty = GETPOSTFLOAT($qty);
					$lineIdToAddLot = 0;
					if ($batch_qty > 0 && !empty($batch_id)) {
						if ($lotStock->fetch($batch_id) > 0) {
							// check if lotStock warehouse id is same as line warehouse id
							if ($lines[$i]->entrepot_id > 0) {
								// single warehouse shipment line
								if ($lines[$i]->entrepot_id == $lotStock->warehouseid) {
									$lineIdToAddLot = $line_id;
								}
							} elseif (count($lines[$i]->details_entrepot) > 1) {
								// multi warehouse shipment lines
								foreach ($lines[$i]->details_entrepot as $detail_entrepot) {
									if ($detail_entrepot->entrepot_id == $lotStock->warehouseid) {
										$lineIdToAddLot = $detail_entrepot->line_id;
									}
								}
							}
							if ($lineIdToAddLot) {
								// add lot to existing line
								if ($line->fetch($lineIdToAddLot) > 0) {
									$line->detail_batch->fk_origin_stock = $batch_id;
									$line->detail_batch->batch = $lotStock->batch;
									$line->detail_batch->entrepot_id = $lotStock->warehouseid;
									$line->detail_batch->qty = $batch_qty;
									if ($line->update($user) < 0) {
										setEventMessages($line->error, $line->errors, 'errors');
										$error++;
									} else {
										$update_done = true;
									}
								} else {
									setEventMessages($line->error, $line->errors, 'errors');
									$error++;
								}
							} else {
								// create new line with new lot
								$line->origin_line_id = $lines[$i]->origin_line_id;
								$line->entrepot_id = $lotStock->warehouseid;
								$line->detail_batch[0] = new ExpeditionLineBatch($db);
								$line->detail_batch[0]->fk_origin_stock = $batch_id;
								$line->detail_batch[0]->batch = $lotStock->batch;
								$line->detail_batch[0]->entrepot_id = $lotStock->warehouseid;
								$line->detail_batch[0]->qty = $batch_qty;
								if ($object->create_line_batch($line, $line->array_options) < 0) {
									setEventMessages($object->error, $object->errors, 'errors');
									$error++;
								} else {
									$update_done = true;
								}
							}
						} else {
							setEventMessages($lotStock->error, $lotStock->errors, 'errors');
							$error++;
						}
					}
				} else {
					if ($lines[$i]->fk_product > 0) {
						// line without lot
						if ($lines[$i]->entrepot_id == 0) {
							// single warehouse shipment line or line in several warehouses context but with warehouse not defined
							$stockLocation = "entl".$line_id;
							$qty = "qtyl".$line_id;
							$line->id = $line_id;
							$line->entrepot_id = GETPOSTINT((string) $stockLocation);
							$line->qty = GETPOSTFLOAT($qty);
							if ($line->update($user) < 0) {
								setEventMessages($line->error, $line->errors, 'errors');
								$error++;
							}
							unset($_POST[$stockLocation]);
							unset($_POST[$qty]);
						} elseif ($lines[$i]->entrepot_id > 0) {
							// single warehouse shipment line
							$stockLocation = "entl".$line_id;
							$qty = "qtyl".$line_id;
							$line->id = $line_id;
							$line->entrepot_id = GETPOSTINT($stockLocation);
							$line->qty = GETPOSTFLOAT($qty);
							if ($line->update($user) < 0) {
								setEventMessages($line->error, $line->errors, 'errors');
								$error++;
							}
							unset($_POST[$stockLocation]);
							unset($_POST[$qty]);
						} elseif (count($lines[$i]->details_entrepot) > 1) {
							// multi warehouse shipment lines
							foreach ($lines[$i]->details_entrepot as $detail_entrepot) {
								if (!$error) {
									$stockLocation = "entl".$detail_entrepot->line_id;
									$qty = "qtyl".$detail_entrepot->line_id;
									$warehouse = GETPOSTINT($stockLocation);
									if (!empty($warehouse)) {
										$line->id = $detail_entrepot->line_id;
										$line->entrepot_id = $warehouse;
										$line->qty = GETPOSTFLOAT($qty);
										if ($line->update($user) < 0) {
											setEventMessages($line->error, $line->errors, 'errors');
											$error++;
										} else {
											$update_done = true;
										}
									}
									unset($_POST[$stockLocation]);
									unset($_POST[$qty]);
								}
							}
						} elseif (!isModEnabled('stock') && empty($conf->productbatch->enabled)) { // both product batch and stock are not activated.
							$qty = "qtyl".$line_id;
							$line->id = $line_id;
							$line->qty = GETPOSTFLOAT($qty);
							$line->entrepot_id = 0;
							if ($line->update($user) < 0) {
								setEventMessages($line->error, $line->errors, 'errors');
								$error++;
							} else {
								$update_done = true;
							}
							unset($_POST[$qty]);
						}
					} else {
						// Product no predefined
						$qty = "qtyl".$line_id;
						$line->id = $line_id;
						$line->qty = GETPOSTFLOAT($qty);
						$line->entrepot_id = 0;
						if ($line->update($user) < 0) {
							setEventMessages($line->error, $line->errors, 'errors');
							$error++;
						} else {
							$update_done = true;
						}
						unset($_POST[$qty]);
					}
				}

				if (empty($update_done)) {
					$line->id = $lines[$i]->id;
					$line->insertExtraFields();
				}
			}
		}

		unset($_POST["lineid"]);

		if (!$error) {
			if (!getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
				// Define output language
				$outputlangs = $langs;
				$newlang = '';
				if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang) && GETPOST('lang_id', 'aZ09')) {
					$newlang = GETPOST('lang_id', 'aZ09');
				}
				if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang)) {
					$newlang = $object->thirdparty->default_lang;
				}
				if (!empty($newlang)) {
					$outputlangs = new Translate("", $conf);
					$outputlangs->setDefaultLang($newlang);
				}

				$ret = $object->fetch($object->id); // Reload to get new records
				$object->generateDocument($object->model_pdf, $outputlangs, $hidedetails, $hidedesc, $hideref);
			}
		} else {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); // To redisplay the form being edited
			exit();
		}
	} elseif ($action == 'updateline' && $permissiontoadd && GETPOST('cancel', 'alpha') == $langs->trans("Cancel")) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); // To redisplay the form being edited
		exit();
	}

	include DOL_DOCUMENT_ROOT.'/core/actions_printing.inc.php';

	// Actions to send emails
	if (empty($id)) {
		$id = $facid;
	}
	$triggersendname = 'SHIPPING_SENTBYMAIL';
	$paramname = 'id';
	$autocopy = 'MAIN_MAIL_AUTOCOPY_SHIPMENT_TO';
	$mode = 'emailfromshipment';
	$trackid = 'shi'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
}


/*
 * View
 */

$title = $object->ref.' - '.$langs->trans("Shipment");
if ($action == 'create2') {
	$title = $langs->trans("CreateShipment");
}
$help_url = 'EN:Module_Shipments|FR:Module_ExpÃƒÂ©ditions|ES:M&oacute;dulo_Expediciones|DE:Modul_Lieferungen';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-expedition page-card');

if (empty($action)) {
	$action = 'view';
}

$form = new Form($db);
$formfile = new FormFile($db);
$formproduct = new FormProduct($db);
if (isModEnabled('project')) {
	$formproject = new FormProjets($db);
} else {
	$formproject = null;
}

$product_static = new Product($db);
$shipment_static = new Expedition($db);
$warehousestatic = new Entrepot($db);

if ($action == 'create2') {
	print load_fiche_titre($langs->trans("CreateShipment"), '', 'dolly');

	print '<br>'.$langs->trans("ShipmentCreationIsDoneFromOrder");
	$action = '';
	$id = '';
	$ref = '';
}

// Mode creation.
if ($action == 'create') {
	$expe = new Expedition($db);

	print load_fiche_titre($langs->trans("CreateShipment"), '', 'dolly');

	if (!$origin) {
		setEventMessages($langs->trans("ErrorBadParameters"), null, 'errors');
	}

	if ($origin) {
		$classname = ucfirst($origin);

		$object = new $classname($db); // This $object is the Sales Order (source object)
		'@phan-var-force Commande|Facture $object';
		if ($object->fetch($origin_id)) {	// This include the fetch_lines
			$soc = new Societe($db);
			$soc->fetch($object->socid);

			$author = new User($db);
			$author->fetch($object->user_author_id);

			if (isModEnabled('stock')) {
				$entrepot = new Entrepot($db);
			}

			print '<form action="'.$_SERVER["PHP_SELF"].'" method="post">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="add">';
			print '<input type="hidden" name="origin" value="'.$origin.'">';
			print '<input type="hidden" name="origin_id" value="'.$object->id.'">';
			if (GETPOSTINT('entrepot_id')) {
				print '<input type="hidden" name="entrepot_id" value="'.GETPOSTINT('entrepot_id').'">';
			}

			print dol_get_fiche_head([]);

			print '<table class="border centpercent">';

			// Ref
			print '<tr><td class="titlefieldcreate fieldrequired">';
			if ($origin == 'commande' && isModEnabled('order')) {
				print $langs->trans("RefOrder");
			}
			if ($origin == 'propal' && isModEnabled("propal")) {
				print $langs->trans("RefProposal");
			}
			print '</td><td colspan="3">';
			print $object->getNomUrl(1);
			print '</td>';
			print "</tr>\n";

			// Ref client
			print '<tr><td>';
			if ($origin == 'commande') {
				print $langs->trans('RefCustomerOrder');
			} elseif ($origin == 'propal') {
				print $langs->trans('RefCustomerOrder');
			} else {
				print $langs->trans('RefCustomer');
			}
			print '</td><td colspan="3">';
			print '<input type="text" name="ref_customer" value="'.$object->ref_client.'" />';
			print '</td>';
			print '</tr>';

			// Tiers
			print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Company').'</td>';
			print '<td colspan="3">'.$soc->getNomUrl(1).'</td>';
			print '</tr>';

			// Project
			if (isModEnabled('project') && is_object($formproject)) {
				$projectid = GETPOSTINT('projectid') ? GETPOSTINT('projectid') : 0;
				if (empty($projectid) && !empty($object->fk_project)) {
					$projectid = $object->fk_project;
				}
				if ($origin == 'project') {
					$projectid = ($originid ? $originid : 0);
				}

				$langs->load("projects");
				print '<tr>';
				print '<td>'.$langs->trans("Project").'</td><td colspan="2">';
				print img_picto('', 'project', 'class="pictofixedwidth"');
				print $formproject->select_projects($soc->id, $projectid, 'projectid', 0, 0, 1, 0, 0, 0, 0, '', 1, 0, 'widthcentpercentminusxx');
				print ' <a class="paddingleft" href="'.DOL_URL_ROOT.'/projet/card.php?socid='.$soc->id.'&action=create&status=1&backtopage='.urlencode($_SERVER["PHP_SELF"].'?action=create&socid='.$soc->id).'"><span class="fa fa-plus-circle valignmiddle"></span></a>';
				print '</td>';
				print '</tr>';
			}

			// Date delivery planned
			print '<tr><td>'.$langs->trans("DateDeliveryPlanned").'</td>';
			print '<td colspan="3">';
			print img_picto('', 'action', 'class="pictofixedwidth"');
			$date_delivery = ($date_delivery ? $date_delivery : $object->delivery_date); // $date_delivery comes from GETPOST
			print $form->selectDate($date_delivery ? $date_delivery : -1, 'date_delivery', 1, 1, 1);
			print "</td>\n";
			print '</tr>';

			// Date sending
			print '<tr><td>'.$langs->trans("DateShipping").'</td>';
			print '<td colspan="3">';
			print img_picto('', 'action', 'class="pictofixedwidth"');
			$date_shipping = ($date_shipping ? $date_shipping : $object->date_shipping); // $date_shipping comes from GETPOST
			print $form->selectDate($date_shipping ? $date_shipping : -1, 'date_shipping', 1, 1, 1);
			print "</td>\n";
			print '</tr>';

			// Note Public
			print '<tr><td>'.$langs->trans("NotePublic").'</td>';
			print '<td colspan="3">';
			$doleditor = new DolEditor('note_public', $object->note_public, '', 60, 'dolibarr_notes', 'In', false, false, !getDolGlobalString('FCKEDITOR_ENABLE_NOTE_PUBLIC') ? 0 : 1, ROWS_3, '90%');
			print $doleditor->Create(1);
			print "</td></tr>";

			// Note Private
			if ($object->note_private && !$user->socid) {
				print '<tr><td>'.$langs->trans("NotePrivate").'</td>';
				print '<td colspan="3">';
				$doleditor = new DolEditor('note_private', $object->note_private, '', 60, 'dolibarr_notes', 'In', false, false, !getDolGlobalString('FCKEDITOR_ENABLE_NOTE_PRIVATE') ? 0 : 1, ROWS_3, '90%');
				print $doleditor->Create(1);
				print "</td></tr>";
			}

			// Weight
			print '<tr><td>';
			print $langs->trans("Weight");
			print '</td><td colspan="3">';
			print img_picto('', 'fa-balance-scale', 'class="pictofixedwidth"');
			print '<input name="weight" size="4" value="'.GETPOSTINT('weight').'"> ';
			$text = $formproduct->selectMeasuringUnits("weight_units", "weight", GETPOSTINT('weight_units'), 0, 2);
			$htmltext = $langs->trans("KeepEmptyForAutoCalculation");
			print $form->textwithpicto($text, $htmltext);
			print '</td></tr>';
			// Dim
			print '<tr><td>';
			print $langs->trans("Width").' x '.$langs->trans("Height").' x '.$langs->trans("Depth");
			print ' </td><td colspan="3">';
			print img_picto('', 'fa-ruler', 'class="pictofixedwidth"');
			print '<input name="sizeW" size="4" value="'.GETPOSTINT('sizeW').'">';
			print ' x <input name="sizeH" size="4" value="'.GETPOSTINT('sizeH').'">';
			print ' x <input name="sizeS" size="4" value="'.GETPOSTINT('sizeS').'">';
			print ' ';
			$text = $formproduct->selectMeasuringUnits("size_units", "size", GETPOSTINT('size_units'), 0, 2);
			$htmltext = $langs->trans("KeepEmptyForAutoCalculation");
			print $form->textwithpicto($text, $htmltext);
			print '</td></tr>';

			// Delivery method
			print "<tr><td>".$langs->trans("DeliveryMethod")."</td>";
			print '<td colspan="3">';
			$expe->fetch_delivery_methods();
			print img_picto('', 'dolly', 'class="pictofixedwidth"');
			print $form->selectarray("shipping_method_id", $expe->meths, GETPOSTINT('shipping_method_id'), 1, 0, 0, "", 1, 0, 0, '', 'widthcentpercentminusxx');
			if ($user->admin) {
				print info_admin($langs->trans("YouCanChangeValuesForThisListFromDictionarySetup"), 1);
			}
			print "</td></tr>\n";

			// Tracking number
			print "<tr><td>".$langs->trans("TrackingNumber")."</td>";
			print '<td colspan="3">';
			print img_picto('', 'barcode', 'class="pictofixedwidth"');
			print '<input name="tracking_number" size="20" value="'.GETPOST('tracking_number', 'alpha').'">';
			print "</td></tr>\n";

			// Other attributes
			$parameters = array('objectsrc' => isset($objectsrc) ? $objectsrc : '', 'colspan' => ' colspan="3"', 'cols' => '3', 'socid' => $socid);
			$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $expe, $action); // Note that $action and $object may have been modified by hook
			print $hookmanager->resPrint;

			if (empty($reshook)) {
				// copy from order
				if ($object->fetch_optionals() > 0) {
					$expe->array_options = array_merge($expe->array_options, $object->array_options);
				}
				print $expe->showOptionals($extrafields, 'edit', $parameters);
			}


			// Incoterms
			if (isModEnabled('incoterm')) {
				print '<tr>';
				print '<td><label for="incoterm_id">'.$form->textwithpicto($langs->trans("IncotermLabel"), $object->label_incoterms, 1).'</label></td>';
				print '<td colspan="3" class="maxwidthonsmartphone">';
				print img_picto('', 'incoterm', 'class="pictofixedwidth"');
				print $form->select_incoterms((!empty($object->fk_incoterms) ? $object->fk_incoterms : ''), (!empty($object->location_incoterms) ? $object->location_incoterms : ''));
				print '</td></tr>';
			}

			// Document model
			include_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
			$list = ModelePdfExpedition::liste_modeles($db);
			if (is_countable($list) && count($list) > 1) {
				print "<tr><td>".$langs->trans("DefaultModel")."</td>";
				print '<td colspan="3">';
				print img_picto('', 'pdf', 'class="pictofixedwidth"');
				print $form->selectarray('model', $list, getDolGlobalString('EXPEDITION_ADDON_PDF'), 0, 0, 0, '', 0, 0, 0, '', 'widthcentpercentminusx');
				print "</td></tr>\n";
			}

			print "</table>";

			print dol_get_fiche_end();

// --- BEGINNING OF NEW CODE BLOCK (Modify llx_commandedet for MO lines) ---
if ($origin == 'commande' && isset($object->id) && $object->id > 0 && !empty($object->lines) && is_array($object->lines)) {
    $product_id_for_mo = 483; // As specified
    // Determine product_type for product 483. Fetching it once would be efficient.
    $product_type_for_mo = null;
    if (!class_exists('Product')) { // Ensure Product class is available
        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
    }
    $temp_product_for_type = new Product($db);
    if ($temp_product_for_type->fetch($product_id_for_mo) > 0) {
        $product_type_for_mo = $temp_product_for_type->type;
    } else {
        dol_syslog("expedition/card.php: Warning - Could not fetch Product ID " . $product_id_for_mo . " to determine its type. MO lines might not have correct product_type set on order line.", LOG_WARNING);
        // Fallback to a generic product type if product 483 cannot be fetched, though this indicates a setup issue.
        $product_type_for_mo = Product::TYPE_PRODUCT;
    }

    if (!class_exists('OrderLine')) { // Ensure OrderLine class is available
        require_once DOL_DOCUMENT_ROOT.'/commande/class/orderline.class.php';
    }

    foreach ($object->lines as $index => $orderLineDataInMemory) {
        // Identify MO line based on its *original* state (empty fk_product and description)
        // $orderLineDataInMemory is a snapshot. We fetch the actual line for update to avoid issues with partial objects.
        $is_mo_line_for_update = false;
        if (empty($orderLineDataInMemory->fk_product) && isset($orderLineDataInMemory->description) &&
            strpos($orderLineDataInMemory->description, 'Costum-PC') === 0 &&  // Check starts with Costum-PC
            strpos($orderLineDataInMemory->description, '(Fabrication)') !== false) { // Check contains (Fabrication)
            $is_mo_line_for_update = true;
        }

        // Only proceed if it's an MO line AND its fk_product in memory is not already the target MO product ID
        // (this check on $orderLineDataInMemory->fk_product helps prevent re-processing if page is reloaded weirdly,
        // though the primary guard is empty fk_product for initial identification)
        if ($is_mo_line_for_update && $orderLineDataInMemory->fk_product != $product_id_for_mo) {
            dol_syslog("expedition/card.php: Attempting to update OrderLine ID " . $orderLineDataInMemory->id . " for MO. Setting fk_product to " . $product_id_for_mo, LOG_DEBUG);

            $line_to_update = new OrderLine($db);
            $fetch_result = $line_to_update->fetch($orderLineDataInMemory->id); // Fetch by ID

            if ($fetch_result > 0) {
                // Double check if it's an MO line based on fetched DB state, if fk_product is still 0 or null
                // This ensures we only update if it hasn't been updated by another process/reload.
                if (empty($line_to_update->fk_product)) {
                    $line_to_update->fk_product = $product_id_for_mo;
                    if ($product_type_for_mo !== null) {
                        $line_to_update->product_type = $product_type_for_mo;
                    }
                    // Note: $user should be available in this context (expedition/card.php)
                    $update_result = $line_to_update->update($user);

                    if ($update_result > 0) {
                        dol_syslog("expedition/card.php: Successfully updated OrderLine ID " . $line_to_update->id . " in DB. fk_product is now " . $product_id_for_mo, LOG_DEBUG);
                        // Update the in-memory $object->lines array as well
                        $object->lines[$index]->fk_product = $product_id_for_mo;
                        if ($product_type_for_mo !== null) {
                             $object->lines[$index]->product_type = $product_type_for_mo;
                        }
                        // If Product 483's label should replace the MO description for display on this page:
                        // $object->lines[$index]->product_label = $temp_product_for_type->label; // Assumes $temp_product_for_type is Product 483
                        // $object->lines[$index]->label = $temp_product_for_type->label;
                    } else {
                        dol_syslog("expedition/card.php: Failed to update OrderLine ID " . $line_to_update->id . " in DB. Error: " . $line_to_update->error, LOG_ERR);
                        setEventMessages($langs->trans("ErrorFailedToUpdateOrderLineForMO", $line_to_update->id) . ': ' . $line_to_update->error, $line_to_update->errors, 'errors');
                    }
                } else {
                     dol_syslog("expedition/card.php: OrderLine ID " . $line_to_update->id . " already has fk_product = " . $line_to_update->fk_product . ". Skipping update.", LOG_DEBUG);
                     // Sync in-memory object if DB was already updated
                     if ($line_to_update->fk_product == $product_id_for_mo) {
                         $object->lines[$index]->fk_product = $line_to_update->fk_product;
                         $object->lines[$index]->product_type = $line_to_update->product_type;
                     }
                }
            } else {
                dol_syslog("expedition/card.php: Failed to fetch OrderLine ID " . $orderLineDataInMemory->id . " for update. Fetch result: " . $fetch_result, LOG_ERR);
                setEventMessages($langs->trans("ErrorFailedToFetchOrderLineForMOUpdate", $orderLineDataInMemory->id), null, 'errors');
            }
        }
    }
}
// --- END OF NEW CODE BLOCK ---

			// Shipment lines

			$numAsked = count($object->lines); // $object is Sales Order here

			print '<script type="text/javascript">'."\n";
			print 'jQuery(document).ready(function() {'."\n";
			print 'jQuery("#autofill").click(function() {';
			$i = 0;
			while ($i < $numAsked) {
				print 'jQuery("#qtyl'.$i.'").val(jQuery("#qtyasked'.$i.'").val() - jQuery("#qtydelivered'.$i.'").val());'."\n";
				if (isModEnabled('productbatch')) {
					// This might need adjustment if MO lines have different batch input IDs
					print 'jQuery("#qtyl'.$i.'_'.$i.'").val(jQuery("#qtyasked'.$i.'").val() - jQuery("#qtydelivered'.$i.'").val());'."\n";
				}
				$i++;
			}
			print 'return false; });'."\n";
			print 'jQuery("#autoreset").click(function() { console.log("Reset values to 0"); jQuery(".qtyl").val(0);'."\n";
			print 'return false; });'."\n";
			print '});'."\n";
			print '</script>'."\n";

			print '<br>';

			print '<div class="div-table-responsive-no-min">';
			print '<table class="noborder centpercent">';

			// Load shipments already done for same order
			$object->loadExpeditions();


			$alreadyQtyBatchSetted = $alreadyQtySetted = array();

			if ($numAsked) {
				print '<tr class="liste_titre">';
				print '<td>'.$langs->trans("Description").'</td>';
				print '<td class="center">'.$langs->trans("QtyOrdered").'</td>';
				print '<td class="center">'.$langs->trans("QtyShipped").'</td>';
				print '<td class="center">'.$langs->trans("QtyToShip");
				if (empty($conf->productbatch->enabled)) { // This check might need to be more nuanced if Product 483 has batches
					print '<br><a href="#" id="autofill" class="opacitymedium link cursor cursorpointer">'.img_picto($langs->trans("Autofill"), 'autofill', 'class="paddingrightonly"').'</a>';
					print ' / ';
				} else {
					print '<br>';
				}
				print '<span id="autoreset" class="opacitymedium link cursor cursorpointer">'.img_picto($langs->trans("Reset"), 'eraser').'</span>';
				print '</td>';
				if (isModEnabled('stock')) {
					if (empty($conf->productbatch->enabled)) { // This check might need to be more nuanced if Product 483 has batches
						print '<td class="left">'.$langs->trans("Warehouse").' ('.$langs->trans("Stock").')</td>';
					} else {
						print '<td class="left">'.$langs->trans("Warehouse").' / '.$langs->trans("Batch").' ('.$langs->trans("Stock").')</td>';
					}
				}
				if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
					print '<td class="left">'.$langs->trans('StockEntryDate').'</td>';
				}
				print "</tr>\n";
			}

			$warehouse_id = GETPOSTINT('entrepot_id');
			$warehousePicking = array();
			// get all warehouse children for picking
			if ($warehouse_id > 0) {
				$warehousePicking[] = $warehouse_id;
				$warehouseObj = new Entrepot($db);
				$warehouseObj->get_children_warehouses($warehouse_id, $warehousePicking);
			}

			$indiceAsked = 0;
			while ($indiceAsked < $numAsked) {
				$product = new Product($db); // Instantiated for each line, good
				$line = $object->lines[$indiceAsked]; // This is the Sales Order line

                // Store original line properties for potential use, especially if MO line's fk_product was just updated.
                $original_line_description = $line->description;
                $original_line_fk_product_type = $line->product_type;
                $original_line_label = $line->label;

                // Consolidated MO line identification, MO ref extraction, and product ID determination for display/logic
                $is_mo_line = false;
                $target_mo_serial_ref = null;
                // Start with the actual fk_product from the line. This will be overridden to 483 if it's an MO line.
                $fk_product_to_use_for_display = $line->fk_product;

                if (isset($line->description) &&
                    strpos($line->description, 'Costum-PC') === 0 &&
                    strpos($line->description, '(Fabrication)') !== false) {

                    $is_mo_line = true;
                    // If it IS an MO line, then fk_product_to_use_for_display should be 483.
                    // The actual Product 483 object will be fetched later when $product->fetch($fk_product_to_use_for_display) is called.
                    $fk_product_to_use_for_display = 483;

                    if (preg_match('/^(Costum-PC\S+)/', trim($line->description), $matches)) {
                        $target_mo_serial_ref = $matches[1];
                    } else {
                        dol_syslog("expedition/card.php (Display Loop): MO Line identified for Order Line ID: " . $line->id . ". Could not extract MO ref from description: '" . $line->description . "'. Expected pattern like 'Costum-PCxxxx-xxxx ...'.", LOG_WARNING);
                    }
                }
                // else: Not an MO line based on description, $fk_product_to_use_for_display remains $line->fk_product (original value)

                // Calculate base_mo_ref_for_line for the current order line
                $base_mo_ref_for_line = '';
                if ($is_mo_line && $fk_product_to_use_for_display == 483 && isset($target_mo_serial_ref) && $target_mo_serial_ref !== null) {
                    if (function_exists('getBaseSerialNumberPart')) {
                        $base_mo_ref_for_line = getBaseSerialNumberPart($target_mo_serial_ref);
                    }
                }
                $original_line_fk_product_type = $line->product_type; // Use $line->product_type if available from fetch_lines()
                $original_line_label = $line->label;

                // Consolidated MO line identification and MO ref extraction
                $is_mo_line = false;
                $target_mo_serial_ref = null; 

                // Identify MO line based *solely* on description pattern now,
                // as fk_product would have been updated to 483 for MO lines in the preceding step (before this loop).
                if (isset($line->description) && 
                    strpos($line->description, 'Costum-PC') === 0 &&  // Check if description starts with "Costum-PC"
                    strpos($line->description, '(Fabrication)') !== false) { // Check if description contains "(Fabrication)"
                    
                    $is_mo_line = true; // This line is identified as an MO line
                    
                    // If it's an MO line, try to extract the MO reference (serial number) from its description
                    if (preg_match('/^(Costum-PC\S+)/', trim($line->description), $matches)) {
                        $target_mo_serial_ref = $matches[1];
                    } else {
                        dol_syslog("expedition/card.php (Display Loop): MO Line identified for Order Line ID: " . $line->id . ". Could not extract MO ref from description: '" . $line->description . "'. Expected pattern like 'Costum-PCxxxx-xxxx ...'.", LOG_WARNING);
                    }
                }

                // Determine the product to use for display.
                $fk_product_to_use_for_display = $line->fk_product;

                // Calculate base_mo_ref_for_line for the current order line
                $base_mo_ref_for_line = ''; // Ensure it's reset for each $line
                if ($is_mo_line && $fk_product_to_use_for_display == 483 && !empty($target_mo_serial_ref)) { // Check !empty for $target_mo_serial_ref
                    if (function_exists('getBaseSerialNumberPart')) {
                        $base_mo_ref_for_line = getBaseSerialNumberPart($target_mo_serial_ref);
                    }
                }
                // If not, it's the original fk_product.
                $fk_product_to_use_for_display = $line->fk_product;
                // $mo_product_id_override = 483; // This is already implicitly handled if $line->fk_product is 483

				$parameters = array('i' => $indiceAsked, 'line' => $line, 'num' => $numAsked);
				$reshook = $hookmanager->executeHooks('printObjectLine', $parameters, $object, $action);
				if ($reshook < 0) {
					setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
				}

				if (empty($reshook)) {
					// Show product and description
                    // The original code determined $type based on $line->product_type or $line->fk_product_type.
                    // For MO lines, $product->type (from product 483) will be more relevant if $fk_product_to_use_for_display > 0.
					$type = $line->product_type ? $line->product_type : $line->fk_product_type; // Original type determination
					if ($fk_product_to_use_for_display > 0 && isset($product->type)) { // If we fetched product 483
                        $type = $product->type; // Use product 483's type
                    } elseif ($is_mo_line) { // MO line but product 483 fetch failed (should be handled) or type not set
                        // Default to product type for MOs if product 483 info is incomplete
                        $type = Product::TYPE_PRODUCT; 
                    } else {
                        // Try to enhance type detection using date_start and date_end for free lines
                        if (!empty($line->date_start)) $type = Product::TYPE_SERVICE;
                        if (!empty($line->date_end)) $type = Product::TYPE_SERVICE;
                    }


					print '<!-- line for order line '.$line->id.' -->'."\n";
					print '<tr class="oddeven" id="row-'.$line->id.'">'."\n";

					// Product label
                    // Modified product label section:
                    if ($fk_product_to_use_for_display > 0) {  // If product is to be used (original or MO override)
                        $resFetchProdDisplay = $product->fetch($fk_product_to_use_for_display); // $product object now holds details of product 483 for MOs
                        if ($resFetchProdDisplay < 0) {
                            setEventMessages($product->error." Product ID: ".$fk_product_to_use_for_display, $product->errors, 'errors'); // More context
                            // Display minimal info or skip if product is critical
                            print '<td>Error fetching product ID: '.$fk_product_to_use_for_display.'</td>';
                        } else {
                            $product->load_stock('warehouseopen'); // Load stock for product 483 (or original product)

                            print '<td>'; // Start of the cell for product description
                            print '<a name="'.$line->id.'"></a>'; // Anchor with original sales order line ID
                            
                            $text_display_name = $product->getNomUrl(1); 

                            if (!$is_mo_line && !empty($original_line_label) && $original_line_label != $product->label) {
                                $text_display_name .= ' - ' . dol_escape_htmltag($original_line_label);
                            }
                        
                            $tooltip_content_for_line = $is_mo_line ? $original_line_description : $line->description;
                            print $form->textwithtooltip($text_display_name, dol_htmlentitiesbr($tooltip_content_for_line), 3, 0, '', $indiceAsked);

                            print_date_range($db->jdate($line->date_start), $db->jdate($line->date_end));

                            if (getDolGlobalString('PRODUIT_DESC_IN_FORM_ACCORDING_TO_DEVICE')) {
                                $supplemental_desc_to_show = $is_mo_line ? $original_line_description : $line->description;
                                if ($supplemental_desc_to_show && $supplemental_desc_to_show != $product->label) { 
                                    print '<br>'.dol_htmlentitiesbr($supplemental_desc_to_show);
                                }
                            }
                            print '</td>';
                        }
                    } else { 
                        // This 'else' is for TRUE free-text lines from sales order (fk_product was 0 AND it was NOT an MO).
                        print "<td>"; 
                        if ($original_line_fk_product_type == 1) { 
                            $text = img_object($langs->trans('Service'), 'service');
                        } else { 
                            $text = img_object($langs->trans('Product'), 'product');
                        }

                        if (!empty($original_line_label)) {
                            $text .= ' <strong>'.dol_escape_htmltag($original_line_label).'</strong>';
                            print $form->textwithtooltip($text, dol_htmlentitiesbr($original_line_description), 3, 0, '', $indiceAsked);
                        } else {
                            print $text.' '.nl2br(dol_htmlentitiesbr($original_line_description));
                        }

                        print_date_range($db->jdate($line->date_start), $db->jdate($line->date_end));
                        print "</td>\n";
                    }


					// unit of order
					$unit_order = '';
					if (getDolGlobalString('PRODUCT_USE_UNITS')) {
						$unit_order = measuringUnitString($line->fk_unit);
					}

					// Qty
					print '<td class="center">'.$line->qty;
					print '<input name="qtyasked'.$indiceAsked.'" id="qtyasked'.$indiceAsked.'" type="hidden" value="'.$line->qty.'">';
					print ''.$unit_order.'</td>';
					$qtyProdCom = $line->qty;

					// Qty already shipped
					print '<td class="center">';
					$quantityDelivered = isset($object->expeditions[$line->id]) ? $object->expeditions[$line->id] : '';
					print $quantityDelivered;
					print '<input name="qtydelivered'.$indiceAsked.'" id="qtydelivered'.$indiceAsked.'" type="hidden" value="'.$quantityDelivered.'">';
					print ''.$unit_order.'</td>';

					// Qty to ship
					$quantityAsked = $line->qty;
                    // $type here should be correctly set for Product 483 if it's an MO line.
					if ($type == Product::TYPE_SERVICE && !getDolGlobalString('STOCK_SUPPORTS_SERVICES') && !getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES')) {
						$quantityToBeDelivered = 0;
					} else {
						if (is_numeric($quantityDelivered)) {
							$quantityToBeDelivered = $quantityAsked - $quantityDelivered;
						} else {
							$quantityToBeDelivered = $quantityAsked;
						}
					}

					$warehouseObject = null;
                    // The condition `!($line->fk_product > 0)` needs to be `!($fk_product_to_use_for_display > 0)` for MO lines
					if (count($warehousePicking) == 1 || !($fk_product_to_use_for_display > 0) || !isModEnabled('stock')) {     // If warehouse was already selected or if product is not a predefined, we go into this part with no multiwarehouse selection
						print '<!-- Case warehouse already known or product not a predefined product -->';
						//ship from preselected location
                        // $product->stock_warehouse comes from product 483 for MO lines
						$stock = + (isset($product->stock_warehouse[$warehouse_id]->real) ? $product->stock_warehouse[$warehouse_id]->real : 0); // Convert to number
						if ($type == Product::TYPE_SERVICE && getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES')) { // $type is from Prod 483 for MO
							$deliverableQty = $quantityToBeDelivered;
						} else {
							$deliverableQty = min($quantityToBeDelivered, $stock);
						}
						if ($deliverableQty < 0) {
							$deliverableQty = 0;
						}
                        // $product->hasbatch() comes from product 483 for MO lines
						if (empty($conf->productbatch->enabled) || !$product->hasbatch()) {
							// Quantity to send
							print '<td class="center">';
                            // $type is from Prod 483 for MO
							if ($type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES') || ($type == Product::TYPE_SERVICE && getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES'))) {
								if (GETPOSTINT('qtyl'.$indiceAsked)) {
									$deliverableQty = GETPOSTINT('qtyl'.$indiceAsked);
								}
								// New condition for readonly and value 0
								$input_readonly = '';
								$final_deliverable_qty = $deliverableQty;
								if ($quantityAsked == $quantityDelivered) {
									$final_deliverable_qty = 0;
									$input_readonly = ' readonly="readonly"';
								}

								print '<input name="idl'.$indiceAsked.'" type="hidden" value="'.$line->id.'">';
								print '<input name="qtyl'.$indiceAsked.'" id="qtyl'.$indiceAsked.'" class="qtyl right" type="text" size="4" value="'.$final_deliverable_qty.'"'.$input_readonly.'>';
							} else {
								if (getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS')) {
									print '<input name="idl'.$indiceAsked.'" type="hidden" value="'.$line->id.'">';
									print '<input name="qtyl'.$indiceAsked.'" id="qtyl'.$indiceAsked.'" type="hidden" value="0">';
								}

								print $langs->trans("NA");
							}
							print '</td>';

							// Stock
							if (isModEnabled('stock')) {
								print '<td class="left">';
                                // $type is from Prod 483 for MO
								if ($type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {   // Type of product need stock change ?
									// Show warehouse combo list
									$ent = "entl".$indiceAsked;
									$idl = "idl".$indiceAsked;
									$tmpentrepot_id = is_numeric(GETPOST($ent)) ? GETPOSTINT($ent) : $warehouse_id;
                                    // $fk_product_to_use_for_display is Prod 483 ID for MO
									if ($fk_product_to_use_for_display > 0) {
										print '<!-- Show warehouse selection -->';

										$stockMin = false;
										if (!getDolGlobalInt('STOCK_ALLOW_NEGATIVE_TRANSFER')) {
											$stockMin = 0;
										}
                                        // selectWarehouses will use $fk_product_to_use_for_display (Prod 483 for MO)
										print $formproduct->selectWarehouses($tmpentrepot_id, 'entl'.$indiceAsked, '', 1, 0, $fk_product_to_use_for_display, '', 1, 0, array(), 'minwidth200', array(), 1, $stockMin, 'stock DESC, e.ref');

										if ($tmpentrepot_id > 0 && $tmpentrepot_id == $warehouse_id) {
											//print $stock.' '.$quantityToBeDelivered;
											if ($stock < $quantityToBeDelivered) {
												print ' '.img_warning($langs->trans("StockTooLow")); // Stock too low for this $warehouse_id but you can change warehouse
											}
										}
									}
								} else {
									print '<span class="opacitymedium">('.$langs->trans("Service").')</span><input name="entl'.$indiceAsked.'" id="entl'.$indiceAsked.'" type="hidden" value="0">';
								}
								print '</td>';
							}
							if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
								print '<td></td>';
							} //StockEntrydate
							print "</tr>\n";

							// Show subproducts of product
                            // $fk_product_to_use_for_display is Prod 483 ID for MO
							if (getDolGlobalString('PRODUIT_SOUSPRODUITS') && $fk_product_to_use_for_display > 0) {
                                // $product object is already Prod 483 for MO
								$product->get_sousproduits_arbo();
								$prods_arbo = $product->get_arbo_each_prod($qtyProdCom); // $qtyProdCom is from original order line
								if (count($prods_arbo) > 0) {
									foreach ($prods_arbo as $key => $value) {
										//print $value[0];
										$img = '';
										if ($value['stock'] < $value['stock_alert']) {
											$img = img_warning($langs->trans("StockTooLow"));
										}
										print "<tr class=\"oddeven\"><td>&nbsp; &nbsp; &nbsp; ->
											<a href=\"".DOL_URL_ROOT."/product/card.php?id=".$value['id']."\">".$value['fullpath']."
											</a> (".$value['nb'].")</td><td class=\"center\"> ".$value['nb_total']."</td><td>&nbsp;</td><td>&nbsp;</td>
											<td class=\"center\">".$value['stock']." ".$img."</td>";
										if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
											print '<td></td>';
										} //StockEntrydate
										print "</tr>";
									}
								}
							}
						} else { // Product needs lot (Product 483 for MO lines)
							// Product need lot
							print '<td></td><td></td>';
							if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
								print '<td></td>';
							} //StockEntrydate
							print '</tr>'; // end line and start a new one for lot/serial
							print '<!-- Case product need lot -->';

							$staticwarehouse = new Entrepot($db);
							if ($warehouse_id > 0) {
								$staticwarehouse->fetch($warehouse_id);
							}

							$subj = 0;
							// Define nb of lines suggested for this order line
							$nbofsuggested = 0;
                            // $product->stock_warehouse is from Prod 483 for MO
							if (is_object($product->stock_warehouse[$warehouse_id]) && count($product->stock_warehouse[$warehouse_id]->detail_batch)) {
								foreach ($product->stock_warehouse[$warehouse_id]->detail_batch as $dbatch) {
									$nbofsuggested++;
								}
							}
							print '<input name="idl'.$indiceAsked.'" type="hidden" value="'.$line->id.'">';
                            // $product->stock_warehouse is from Prod 483 for MO
							if (is_object($product->stock_warehouse[$warehouse_id]) && count($product->stock_warehouse[$warehouse_id]->detail_batch)) {
								foreach ($product->stock_warehouse[$warehouse_id]->detail_batch as $dbatch) {	// $dbatch is instance of Productbatch
									//var_dump($dbatch);
									$batchStock = + $dbatch->qty; // To get a numeric
									$deliverableQty = min($quantityToBeDelivered, $batchStock);

									// Now we will check if we have to reduce the deliverableQty by taking into account the qty already suggested in previous line
                                    // $fk_product_to_use_for_display is Prod 483 ID for MO
									if (isset($alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)])) {
										$deliverableQty = min($quantityToBeDelivered, $batchStock - $alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)]);
									} else {
										if (!isset($alreadyQtyBatchSetted[$fk_product_to_use_for_display])) {
											$alreadyQtyBatchSetted[$fk_product_to_use_for_display] = array();
										}

										if (!isset($alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch])) {
											$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch] = array();
										}

										$deliverableQty = min($quantityToBeDelivered, $batchStock);
									}

									if ($deliverableQty < 0) {
										$deliverableQty = 0;
									}

									$inputName = 'qtyl'.$indiceAsked.'_'.$subj;
									if (GETPOSTISSET($inputName)) {
										$deliverableQty = GETPOST($inputName, 'int');
									}

									$tooltipClass = $tooltipTitle = '';
                                    // $fk_product_to_use_for_display is Prod 483 ID for MO
									if (!empty($alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)])) {
										$tooltipClass = ' classfortooltip';
										$tooltipTitle = $langs->trans('StockQuantitiesAlreadyAllocatedOnPreviousLines').' : '.$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)];
									} else {
										$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)] = 0 ;
									}
									$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)] = $deliverableQty + $alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)];

									print '<!-- subj='.$subj.'/'.$nbofsuggested.' --><tr '.((($subj + 1) == $nbofsuggested) ? 'oddeven' : '').'>';
									print '<td colspan="3" ></td><td class="center">';

									$current_full_serial_for_attr = $dbatch->batch;
									$data_attrs_for_qty_input = ' data-serial="'.dol_escape_htmltag($current_full_serial_for_attr).'"';
									// $base_mo_ref_for_line was calculated for the parent order line ($line)
									// $fk_product_to_use_for_display is also for the parent order line
									if ($is_mo_line && $fk_product_to_use_for_display == 483 && !empty($base_mo_ref_for_line)) {
										$data_attrs_for_qty_input .= ' data-basemoref="'.dol_escape_htmltag($base_mo_ref_for_line).'"';
									}
									// New condition for readonly and value 0
									$input_readonly_batch_single_wh = '';
									$final_deliverable_qty_batch_single_wh = $deliverableQty;
									// $quantityAsked and $quantityDelivered are for the parent order line
									if ($quantityAsked == $quantityDelivered) {
										$final_deliverable_qty_batch_single_wh = 0;
										$input_readonly_batch_single_wh = ' readonly="readonly"';
									}
// PHP $disabled_attr is removed from the input tag. JS will handle enabling/disabling.
print '<input class="qtyl mo-serial-qty-input '.$tooltipClass.' right" title="'.$tooltipTitle.'" name="qtyl'.$indiceAsked.'_'.$subj.'" id="qtyl'.$indiceAsked.'_'.$subj.'" type="text" size="4" value="'.$final_deliverable_qty_batch_single_wh.'"'.$data_attrs_for_qty_input.$input_readonly_batch_single_wh.'>';
									print '</td>';

									print '<!-- Show details of lot -->';
									print '<td class="left">';

									print $staticwarehouse->getNomUrl(0).' / ';

									// Prepare MO filter for selectLotStock if applicable
									$mo_filter_for_selectlotstock_card = null;
									$base_mo_ref_for_js_attr = '';
									$select_html_name = 'batchl'.$indiceAsked.'_'.$subj;
									$select_more_css = 'minwidth150';

									if ($is_mo_line && $fk_product_to_use_for_display == 483 && isset($target_mo_serial_ref) && $target_mo_serial_ref !== null) {
										$mo_filter_for_selectlotstock_card = $target_mo_serial_ref;
										if (function_exists('getBaseSerialNumberPart')) {
											$base_mo_ref_for_js_attr = getBaseSerialNumberPart($target_mo_serial_ref);
										}
										$select_more_css .= ' mo-product483-serial-select'; // Add class
									}

									if (getDolGlobalString('CONFIG_MAIN_FORCELISTOFBATCHISCOMBOBOX')) {
										// Parameters for selectLotStock: $selected, $htmlname, $filterstatus, $empty, $disabled, $fk_product, $fk_entrepot, $objectLines, $empty_label, $forcecombo, $events, $morecss, $mo_ref_filter
										print $formproduct->selectLotStock($dbatch->id, $select_html_name, '', 1, 0, $fk_product_to_use_for_display, $warehouse_id, array(), '', 1, array(), $select_more_css, $mo_filter_for_selectlotstock_card);
										if (!empty($base_mo_ref_for_js_attr)) {
											print '<script type="text/javascript">$(document).ready(function() { $("#'.$select_html_name.'").attr("data-basemoref", "'.dol_escape_js($base_mo_ref_for_js_attr).'"); });</script>';
										}
									} else {
										print '<input name="'.$select_html_name.'" type="hidden" value="'.$dbatch->id.'">';
										// Display the batch number textually. If it's an MO line for Product 483, this text is just for info, selection happens if forcecombo is on.
										print $dbatch->batch;
									}

									$detail = '';
									// $detail .= $langs->trans("Batch").': '.$dbatch->batch; // Batch already shown or part of select
									if (!getDolGlobalString('PRODUCT_DISABLE_SELLBY') && !empty($dbatch->sellby)) {
										$detail .= ' - '.$langs->trans("SellByDate").': '.dol_print_date($dbatch->sellby, "day");
									}
									if (!getDolGlobalString('PRODUCT_DISABLE_EATBY') && !empty($dbatch->eatby)) {
										$detail .= ' - '.$langs->trans("EatByDate").': '.dol_print_date($dbatch->eatby, "day");
									}
									$detail .= ' - '.$langs->trans("Qty").': '.$dbatch->qty;
									$detail .= '<br>';
									print $detail;

									$quantityToBeDelivered -= $deliverableQty;
									if ($quantityToBeDelivered < 0) {
										$quantityToBeDelivered = 0;
									}
									$subj++;
									print '</td>';
									if (getDolGlobalInt('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
										print '<td>'.dol_print_date($dbatch->context['stock_entry_date'], 'day').'</td>'; //StockEntrydate
									}
									print '</tr>';
								}
							} else {
								print '<!-- Case there is no details of lot at all -->';
								print '<tr class="oddeven"><td colspan="3"></td><td class="center">';
								print '<input class="qtyl right" name="qtyl'.$indiceAsked.'_'.$subj.'" id="qtyl'.$indiceAsked.'_'.$subj.'" type="text" size="4" value="0" disabled="disabled"> ';
								print '</td>';

								print '<td class="left">';
								print img_warning().' '.$langs->trans("NoProductToShipFoundIntoStock", $staticwarehouse->label);
								print '</td>';
								if (getDolGlobalInt('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
									print '<td></td>';
								} //StockEntrydate
								print '</tr>';
							}
						}
					} else { // ship from multiple locations (product is Product 483 for MO)
						// ship from multiple locations
                        // $product->hasbatch() is from Prod 483 for MO
						if (empty($conf->productbatch->enabled) || !$product->hasbatch()) {
							print '<!-- Case warehouse not already known and product does not need lot -->';
							print '<td></td><td></td>';
							if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
								print '<td></td>';
							}//StockEntrydate
							print '</tr>'."\n"; // end line and start a new one for each warehouse

							print '<input name="idl'.$indiceAsked.'" type="hidden" value="'.$line->id.'">';
							$subj = 0;
							// Define nb of lines suggested for this order line
							$nbofsuggested = 0;

                            // $product->stock_warehouse is from Prod 483 for MO
							foreach ($product->stock_warehouse as $warehouse_id => $stock_warehouse) {
								if ($stock_warehouse->real > 0 || !empty($conf->global->STOCK_ALLOW_NEGATIVE_TRANSFER)) {
									$nbofsuggested++;
								}
							}
							$tmpwarehouseObject = new Entrepot($db);
                            // $product->stock_warehouse is from Prod 483 for MO
							foreach ($product->stock_warehouse as $warehouse_id => $stock_warehouse) {    // $stock_warehouse is product_stock
								$var = $subj % 2;
								if (!empty($warehousePicking) && !in_array($warehouse_id, $warehousePicking)) {
									// if a warehouse was selected by user, picking is limited to this warehouse and his children
									continue;
								}

								$tmpwarehouseObject->fetch($warehouse_id);
								if ($stock_warehouse->real > 0 || !empty($conf->global->STOCK_ALLOW_NEGATIVE_TRANSFER)) {
									$stock = + $stock_warehouse->real; // Convert it to number
									$deliverableQty = min($quantityToBeDelivered, $stock);
									$deliverableQty = max(0, $deliverableQty);
									// Quantity to send
									print '<!-- subj='.$subj.'/'.$nbofsuggested.' --><tr '.((($subj + 1) == $nbofsuggested) ? 'oddeven' : '').'>';
									print '<td colspan="3" ></td><td class="center"><!-- qty to ship (no lot management for product line indiceAsked='.$indiceAsked.') -->';
                                    // $type is from Prod 483 for MO
									if ($type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES') || getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES')) {
                                        // $fk_product_to_use_for_display is Prod 483 ID for MO
										if (isset($alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)])) {
											$deliverableQty = min($quantityToBeDelivered, $stock - $alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)]);
										} else {
											if (!isset($alreadyQtySetted[$fk_product_to_use_for_display])) {
												$alreadyQtySetted[$fk_product_to_use_for_display] = array();
											}

											$deliverableQty = min($quantityToBeDelivered, $stock);
										}

										if ($deliverableQty < 0) {
											$deliverableQty = 0;
										}

										$tooltipClass = $tooltipTitle = '';
                                        // $fk_product_to_use_for_display is Prod 483 ID for MO
										if (!empty($alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)])) {
											$tooltipClass = ' classfortooltip';
											$tooltipTitle = $langs->trans('StockQuantitiesAlreadyAllocatedOnPreviousLines').' : '.$alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)];
										} else {
											$alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)] = 0;
										}

										$alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)] = $deliverableQty + $alreadyQtySetted[$fk_product_to_use_for_display][intval($warehouse_id)];

										$inputName = 'qtyl'.$indiceAsked.'_'.$subj;
										if (GETPOSTISSET($inputName)) {
											$deliverableQty = GETPOSTINT($inputName);
										}
										// New condition for readonly and value 0
										$input_readonly_multi_wh_no_batch = '';
										$final_deliverable_qty_multi_wh_no_batch = $deliverableQty;
										if ($quantityAsked == $quantityDelivered) {
											$final_deliverable_qty_multi_wh_no_batch = 0;
											$input_readonly_multi_wh_no_batch = ' readonly="readonly"';
										}

										print '<input class="qtyl'.$tooltipClass.' right" title="'.$tooltipTitle.'" name="qtyl'.$indiceAsked.'_'.$subj.'" id="qtyl'.$indiceAsked.'" type="text" size="4" value="'.$final_deliverable_qty_multi_wh_no_batch.'"'.$input_readonly_multi_wh_no_batch.'>';
										print '<input name="ent1'.$indiceAsked.'_'.$subj.'" type="hidden" value="'.$warehouse_id.'">';
									} else {
										if (getDolGlobalString('SHIPMENT_GETS_ALL_ORDER_PRODUCTS')) {
											print '<input name="qtyl'.$indiceAsked.'_'.$subj.'" id="qtyl'.$indiceAsked.'" type="hidden" value="0">';
										}

										print $langs->trans("NA");
									}
									print '</td>';

									// Stock
									if (isModEnabled('stock')) {
										print '<td class="left">';
                                        // $type is from Prod 483 for MO
										if ($type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
											print $tmpwarehouseObject->getNomUrl(0).' ';

											print '<!-- Show details of stock -->';
											print '('.$stock.')';
										} else {
											print '<span class="opacitymedium">('.$langs->trans("Service").')</span>';
										}
										print '</td>';
									}
									$quantityToBeDelivered -= $deliverableQty;
									if ($quantityToBeDelivered < 0) {
										$quantityToBeDelivered = 0;
									}
									$subj++;
									if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
										print '<td></td>';
									}//StockEntrydate
									print "</tr>\n";
								}
							}
							// Show subproducts of product (not recommended)
                            // $fk_product_to_use_for_display is Prod 483 ID for MO
							if (getDolGlobalString('PRODUIT_SOUSPRODUITS') && $fk_product_to_use_for_display > 0) {
                                // $product is Prod 483 for MO
								$product->get_sousproduits_arbo();
								$prods_arbo = $product->get_arbo_each_prod($qtyProdCom);
								if (count($prods_arbo) > 0) {
									foreach ($prods_arbo as $key => $value) {
										//print $value[0];
										$img = '';
										if ($value['stock'] < $value['stock_alert']) {
											$img = img_warning($langs->trans("StockTooLow"));
										}
										print '<tr class"oddeven"><td>';
										print "&nbsp; &nbsp; &nbsp; ->
										<a href=\"".DOL_URL_ROOT."/product/card.php?id=".$value['id']."\">".$value['fullpath']."
										</a> (".$value['nb'].")</td><td class=\"center\"> ".$value['nb_total']."</td><td>&nbsp;</td><td>&nbsp;</td>
										<td class=\"center\">".$value['stock']." ".$img."</td>";
										if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
											print '<td></td>';
										}//StockEntrydate
										print "</tr>";
									}
								}
							}
						} else { // Product needs lot (Product 483 for MO)
							print '<!-- Case warehouse not already known and product need lot -->';
							print '<td></td><td></td>';
							if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
								print '<td></td>';
							}//StockEntrydate
							print '</tr>'; // end line and start a new one for lot/serial

							$subj = 0;
							print '<input name="idl'.$indiceAsked.'" type="hidden" value="'.$line->id.'">';

							$tmpwarehouseObject = new Entrepot($db);
							$productlotObject = new Productlot($db);

							// Define nb of lines suggested for this order line
							$nbofsuggested = 0;
                            // $product->stock_warehouse is from Prod 483 for MO
							foreach ($product->stock_warehouse as $warehouse_id => $stock_warehouse) {
								if (($stock_warehouse->real > 0 || !empty($conf->global->STOCK_ALLOW_NEGATIVE_TRANSFER)) && (count($stock_warehouse->detail_batch))) {
									$nbofsuggested += count($stock_warehouse->detail_batch);
								}
							}

                            // $product->stock_warehouse is from Prod 483 for MO
							foreach ($product->stock_warehouse as $warehouse_id => $stock_warehouse) {
								$var = $subj % 2;
								if (!empty($warehousePicking) && !in_array($warehouse_id, $warehousePicking)) {
									// if a warehouse was selected by user, picking is limited to this warehouse and his children
									continue;
								}

								$tmpwarehouseObject->fetch($warehouse_id);
								if (($stock_warehouse->real > 0 || !empty($conf->global->STOCK_ALLOW_NEGATIVE_TRANSFER)) && (count($stock_warehouse->detail_batch))) {
									foreach ($stock_warehouse->detail_batch as $dbatch) {
										$batchStock = + $dbatch->qty; // To get a numeric
                                        // $fk_product_to_use_for_display is Prod 483 ID for MO
										if (isset($alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)])) {
											$deliverableQty = min($quantityToBeDelivered, $batchStock - $alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)]);
										} else {
											if (!isset($alreadyQtyBatchSetted[$fk_product_to_use_for_display])) {
												$alreadyQtyBatchSetted[$fk_product_to_use_for_display] = array();
											}

											if (!isset($alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch])) {
												$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch] = array();
											}

											$deliverableQty = min($quantityToBeDelivered, $batchStock);
										}

										if ($deliverableQty < 0) {
											$deliverableQty = 0;
										}

										$inputName = 'qtyl'.$indiceAsked.'_'.$subj;
										if (GETPOSTISSET($inputName)) {
											$deliverableQty = GETPOSTINT($inputName);
										}

										$tooltipClass = $tooltipTitle = '';
                                        // $fk_product_to_use_for_display is Prod 483 ID for MO
										if (!empty($alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)])) {
											$tooltipClass = ' classfortooltip';
											$tooltipTitle = $langs->trans('StockQuantitiesAlreadyAllocatedOnPreviousLines').' : '.$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)];
										} else {
											$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)] = 0 ;
										}
										$alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)] = $deliverableQty + $alreadyQtyBatchSetted[$fk_product_to_use_for_display][$dbatch->batch][intval($warehouse_id)];

										print '<!-- subj='.$subj.'/'.$nbofsuggested.' --><tr '.((($subj + 1) == $nbofsuggested) ? 'oddeven' : '').'><td colspan="3"></td><td class="center">';

										$current_full_serial_for_attr_multiwh = $dbatch->batch;
										$data_attrs_for_qty_input_multiwh = ' data-serial="'.dol_escape_htmltag($current_full_serial_for_attr_multiwh).'"';
										// $base_mo_ref_for_line was calculated for the parent order line ($line)
										// $fk_product_to_use_for_display is also for the parent order line
										if ($is_mo_line && $fk_product_to_use_for_display == 483 && !empty($base_mo_ref_for_line)) {
											$data_attrs_for_qty_input_multiwh .= ' data-basemoref="'.dol_escape_htmltag($base_mo_ref_for_line).'"';
										}
										// New condition for readonly and value 0
										$input_readonly_multi_wh_batch = '';
										$final_deliverable_qty_multi_wh_batch = $deliverableQty;
										if ($quantityAsked == $quantityDelivered) {
											$final_deliverable_qty_multi_wh_batch = 0;
											$input_readonly_multi_wh_batch = ' readonly="readonly"';
										}
// PHP $disabled_attr is removed from the input tag. JS will handle enabling/disabling.
print '<input class="qtyl mo-serial-qty-input right '.$tooltipClass.'" title="'.$tooltipTitle.'" name="'.$inputName.'" id="'.$inputName.'" type="text" size="4" value="'.$final_deliverable_qty_multi_wh_batch.'"'.$data_attrs_for_qty_input_multiwh.$input_readonly_multi_wh_batch.'>';
										print '</td>';

										print '<td class="left">';

										print $tmpwarehouseObject->getNomUrl(0).' / ';

										// Prepare MO filter for selectLotStock if applicable
										$mo_filter_for_selectlotstock_card = null;
										$base_mo_ref_for_js_attr = '';
										$select_html_name_multiwh = 'batchl'.$indiceAsked.'_'.$subj; // Ensure unique htmlname if needed, though $subj should make it unique in this loop iteration
										$select_more_css_multiwh = 'minwidth150';

										if ($is_mo_line && $fk_product_to_use_for_display == 483 && isset($target_mo_serial_ref) && $target_mo_serial_ref !== null) {
											$mo_filter_for_selectlotstock_card = $target_mo_serial_ref;
											if (function_exists('getBaseSerialNumberPart')) {
												$base_mo_ref_for_js_attr = getBaseSerialNumberPart($target_mo_serial_ref);
											}
											$select_more_css_multiwh .= ' mo-product483-serial-select'; // Add class
										}

										if (getDolGlobalString('CONFIG_MAIN_FORCELISTOFBATCHISCOMBOBOX')) {
											// Parameters for selectLotStock: $selected, $htmlname, $filterstatus, $empty, $disabled, $fk_product, $fk_entrepot, $objectLines, $empty_label, $forcecombo, $events, $morecss, $mo_ref_filter
											print $formproduct->selectLotStock($dbatch->id, $select_html_name_multiwh, '', 1, 0, $fk_product_to_use_for_display, $warehouse_id, array(), '', 1, array(), $select_more_css_multiwh, $mo_filter_for_selectlotstock_card);
											if (!empty($base_mo_ref_for_js_attr)) {
												print '<script type="text/javascript">$(document).ready(function() { $("#'.$select_html_name_multiwh.'").attr("data-basemoref", "'.dol_escape_js($base_mo_ref_for_js_attr).'"); });</script>';
											}
										} else {
											print '<!-- Show details of lot -->';
											print '<input name="'.$select_html_name_multiwh.'" type="hidden" value="'.$dbatch->id.'">';
											// Display the batch number textually. If it's an MO line for Product 483, this text is just for info, selection happens if forcecombo is on.
											// print $dbatch->batch; // This line is removed as requested by implication of using selectLotStock or ensuring the value comes from what is selected.
											// The actual display of the batch number if not using forcecombo will be handled by iterating $dbatch->batch,
											// but the selectLotStock (if used) or selectLotDataList (implicitly using filtered loadLotStock) handles available options.
											// For non-forcecombo, we still want to show the batch name.
											$resultFetchBatch = $productlotObject->fetch($dbatch->id); // $dbatch->id is fk_product_batch
											if ($resultFetchBatch > 0) {
												print $productlotObject->getNomUrl(1);
											} else {
												print $dbatch->batch; // Fallback to raw batch name
											}
										}

										//print '|'.$line->fk_product.'|'.$dbatch->batch.'|<br>';
                                        // $fk_product_to_use_for_display is Prod 483 ID for MO
										// print $langs->trans("Batch").': '; // Batch already shown or part of select
										// $result = $productlotObject->fetch(0, $fk_product_to_use_for_display, $dbatch->batch); // This was incorrect, should fetch by $dbatch->id
										// if ($result > 0) {
										// 	print $productlotObject->getNomUrl(1);
										// } else {
										// 	print $langs->trans("TableLotIncompleteRunRepairWithParamStandardEqualConfirmed");
										// }
										if (!getDolGlobalString('PRODUCT_DISABLE_SELLBY') && !empty($dbatch->sellby)) {
											print ' - '.$langs->trans("SellByDate").': '.dol_print_date($dbatch->sellby, "day");
										}
										if (!getDolGlobalString('PRODUCT_DISABLE_EATBY') && !empty($dbatch->eatby)) {
											print ' - '.$langs->trans("EatByDate").': '.dol_print_date($dbatch->eatby, "day");
										}
										print ' ('.$dbatch->qty.')';
										$quantityToBeDelivered -= $deliverableQty;
										if ($quantityToBeDelivered < 0) {
											$quantityToBeDelivered = 0;
										}
										//dol_syslog('deliverableQty = '.$deliverableQty.' batchStock = '.$batchStock);
										$subj++;
										print '</td>';
										if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
											print '<td class="left">'.dol_print_date($dbatch->context['stock_entry_date'], 'day').'</td>';
										}
										print '</tr>';
									}
								}
							}
						}
						if ($subj == 0) { // Line not shown yet, we show it
							$warehouse_selected_id = GETPOSTINT('entrepot_id');

							print '<!-- line not shown yet, we show it -->';
							print '<tr class="oddeven"><td colspan="3"></td><td class="center">';

                            // $type is from Prod 483 for MO
							if ($type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
								$disabled = '';
                                // $product->hasbatch() is from Prod 483 for MO
								if (isModEnabled('productbatch') && $product->hasbatch()) {
									$disabled = 'disabled="disabled"';
								}
								if ($warehouse_selected_id <= 0) {		// We did not force a given warehouse, so we won't have no warehouse to change qty.
									$disabled = 'disabled="disabled"';
								}
								// New condition for readonly and value 0
								$input_readonly_no_stock_prod = '';
								$final_val_no_stock_prod = 0;
								if ($quantityAsked == $quantityDelivered) {
									// Value is already 0, just make it readonly
									$input_readonly_no_stock_prod = ' readonly="readonly"';
								}
								print '<input class="qtyl right" name="qtyl'.$indiceAsked.'_'.$subj.'" id="qtyl'.$indiceAsked.'_'.$subj.'" type="text" size="4" value="'.$final_val_no_stock_prod.'"'.($disabled ? ' '.$disabled : '').$input_readonly_no_stock_prod.'> ';
								if (empty($disabled) && getDolGlobalString('STOCK_ALLOW_NEGATIVE_TRANSFER') && $quantityAsked > $quantityDelivered) { // Only add hidden input if not fully shipped
									print '<input name="ent1' . $indiceAsked . '_' . $subj . '" type="hidden" value="' . $warehouse_selected_id . '">';
								}
                            // $type is from Prod 483 for MO
							} elseif ($type == Product::TYPE_SERVICE && getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES')) {
								$disabled = '';
                                // $product->hasbatch() is from Prod 483 for MO
								if (isModEnabled('productbatch') && $product->hasbatch()) {
									$disabled = 'disabled="disabled"';
								}
								if ($warehouse_selected_id <= 0) {		// We did not force a given warehouse, so we won't have no warehouse to change qty.
									$disabled = 'disabled="disabled"';
								}
								// New condition for readonly and value 0
								$input_readonly_no_stock_serv = '';
								$final_val_no_stock_serv = $quantityToBeDelivered;
								if ($quantityAsked == $quantityDelivered) {
									$final_val_no_stock_serv = 0;
									$input_readonly_no_stock_serv = ' readonly="readonly"';
								}
								print '<input class="qtyl right" name="qtyl'.$indiceAsked.'_'.$subj.'" id="qtyl'.$indiceAsked.'_'.$subj.'" type="text" size="4" value="'.$final_val_no_stock_serv.'"'.($disabled ? ' '.$disabled : '').$input_readonly_no_stock_serv.'> ';
								if (empty($disabled) && getDolGlobalString('STOCK_ALLOW_NEGATIVE_TRANSFER') && $quantityAsked > $quantityDelivered) { // Only add hidden input if not fully shipped
									print '<input name="ent1' . $indiceAsked . '_' . $subj . '" type="hidden" value="' . $warehouse_selected_id . '">';
								}
							} else {
								print $langs->trans("NA");
							}
							print '</td>';

							print '<td class="left">';
                            // $type is from Prod 483 for MO
							if ($type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
								if ($warehouse_selected_id > 0) {
									$warehouseObject = new Entrepot($db);
									$warehouseObject->fetch($warehouse_selected_id);
									print img_warning().' '.$langs->trans("NoProductToShipFoundIntoStock", $warehouseObject->label);
								} else {
                                    // $fk_product_to_use_for_display is Prod 483 for MO
									if ($fk_product_to_use_for_display) { // Check if product is defined (Prod 483 for MO)
										print img_warning().' '.$langs->trans("StockTooLow");
									} else { // True free text line (no product)
										print '';
									}
								}
							} else {
								print '<span class="opacitymedium">('.$langs->trans("Service").')</span>';
							}
							print '</td>';
							if (getDolGlobalString('SHIPPING_DISPLAY_STOCK_ENTRY_DATE')) {
								print '<td></td>';
							}//StockEntrydate
							print '</tr>';
						}
					}

					// Display lines for extrafields of the Shipment line
					// $line is a 'Order line'
					if (!empty($extrafields)) {
						//var_dump($line);
						$colspan = 5;
						$expLine = new ExpeditionLigne($db);

						$srcLine = new OrderLine($db);
						$srcLine->id = $line->id;
						$srcLine->fetch_optionals(); // fetch extrafields also available in orderline

						$expLine->array_options = array_merge($expLine->array_options, $srcLine->array_options);

						print $expLine->showOptionals($extrafields, 'edit', array('style' => 'class="drag drop oddeven"', 'colspan' => $colspan), $indiceAsked, '', 1);
					}
				}

				$indiceAsked++;
			}

			print "</table>";
			print '</div>';

			print '<br>';

			print $form->buttonsSaveCancel("Create");

			print '</form>';

			print '<br>';
		} else {
			dol_print_error($db);
		}
	}
} elseif ($object->id > 0) {
	'@phan-var-force Expedition $object';  // Need to force it (type overridden earlier)

	// Edit and view mode

	$lines = $object->lines;

	$num_prod = count($lines);

	if (!empty($object->origin) && $object->origin_id > 0) {
		$typeobject = $object->origin;
		$origin = $object->origin;
		$origin_id = $object->origin_id;

		$object->fetch_origin(); // Load property $object->origin_object (old $object->commande, $object->propal, ...)
	}

	$soc = new Societe($db);
	$soc->fetch($object->socid);

	$res = $object->fetch_optionals();

	$head = shipping_prepare_head($object);
	print dol_get_fiche_head($head, 'shipping', $langs->trans("Shipment"), -1, $object->picto);

	$formconfirm = '';

	// Confirm deletion
	if ($action == 'delete') {
		$formquestion = array();
		if ($object->status == Expedition::STATUS_CLOSED && getDolGlobalString('STOCK_CALCULATE_ON_SHIPMENT_CLOSE')) {
			$formquestion = array(
					array(
						'label' => $langs->trans('ShipmentIncrementStockOnDelete'),
						'name' => 'alsoUpdateStock',
						'type' => 'checkbox',
						'value' => 0
					),
				);
		}
		$formconfirm = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.$object->id,
			$langs->trans('DeleteSending'),
			$langs->trans("ConfirmDeleteSending", $object->ref),
			'confirm_delete',
			$formquestion,
			0,
			1
		);
	}

	// Confirmation validation
	if ($action == 'valid') {
		$objectref = substr($object->ref, 1, 4);
		if ($objectref == 'PROV') {
			$numref = $object->getNextNumRef($soc);
		} else {
			$numref = $object->ref;
		}

		$text = $langs->trans("ConfirmValidateSending", $numref);
		if (getDolGlobalString('STOCK_CALCULATE_ON_SHIPMENT')) {
			$text .= '<br>'.img_picto('', 'movement', 'class="pictofixedwidth"').$langs->trans("StockMovementWillBeRecorded").'.';
		} elseif (getDolGlobalString('STOCK_CALCULATE_ON_SHIPMENT_CLOSE')) {
			$text .= '<br>'.img_picto('', 'movement', 'class="pictofixedwidth"').$langs->trans("StockMovementNotYetRecorded").'.';
		}

		if (isModEnabled('notification')) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/notify.class.php';
			$notify = new Notify($db);
			$text .= '<br>';
			$text .= $notify->confirmMessage('SHIPPING_VALIDATE', $object->socid, $object);
		}

		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('ValidateSending'), $text, 'confirm_valid', '', 0, 1, 250);
	}
	// Confirm cancellation
	if ($action == 'cancel') {
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('CancelSending'), $langs->trans("ConfirmCancelSending", $object->ref), 'confirm_cancel', '', 0, 1);
	}

	// Call Hook formConfirm
	$parameters = array('formConfirm' => $formconfirm);
	$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	if (empty($reshook)) {
		$formconfirm .= $hookmanager->resPrint;
	} elseif ($reshook > 0) {
		$formconfirm = $hookmanager->resPrint;
	}

	// Print form confirm
	print $formconfirm;

	// Calculate totalWeight and totalVolume for all products
	// by adding weight and volume of each product line.
	$tmparray = $object->getTotalWeightVolume();
	$totalWeight = $tmparray['weight'];
	$totalVolume = $tmparray['volume'];

	if (!empty($typeobject) && $typeobject === 'commande' && is_object($object->origin_object) && $object->origin_object->id && isModEnabled('order')) {
		$objectsrc = new Commande($db);
		$objectsrc->fetch($object->origin_object->id);
	}
	if (!empty($typeobject) && $typeobject === 'propal' && is_object($object->origin_object) && $object->origin_object->id && isModEnabled("propal")) {
		$objectsrc = new Propal($db);
		$objectsrc->fetch($object->origin_object->id);
	}

	// Shipment card
	$linkback = '<a href="'.DOL_URL_ROOT.'/expedition/list.php?restore_lastsearch_values=1'.(!empty($socid) ? '&socid='.$socid : '').'">'.$langs->trans("BackToList").'</a>';
	$morehtmlref = '<div class="refidno">';
	// Ref customer shipment
	$morehtmlref .= $form->editfieldkey("RefCustomer", 'ref_customer', $object->ref_customer, $object, $user->hasRight('expedition', 'creer'), 'string', '', 0, 1);
	$morehtmlref .= $form->editfieldval("RefCustomer", 'ref_customer', $object->ref_customer, $object, $user->hasRight('expedition', 'creer'), 'string'.(isset($conf->global->THIRDPARTY_REF_INPUT_SIZE) ? ':' . getDolGlobalString('THIRDPARTY_REF_INPUT_SIZE') : ''), '', null, null, '', 1);
	// Thirdparty
	$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1);
	// Project
	if (isModEnabled('project')) {
		$langs->load("projects");
		$morehtmlref .= '<br>';
		if (0) {	// Do not change on shipment
			$morehtmlref .= img_picto($langs->trans("Project"), 'project', 'class="pictofixedwidth"');
			if ($action != 'classify') {
				$morehtmlref .= '<a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?action=classify&token='.newToken().'&id='.$object->id.'">'.img_edit($langs->transnoentitiesnoconv('SetProject')).'</a> ';
			}
			$morehtmlref .= $form->form_project($_SERVER['PHP_SELF'].'?id='.$object->id, $objectsrc->socid, $objectsrc->fk_project, ($action == 'classify' ? 'projectid' : 'none'), 0, 0, 0, 1, '', 'maxwidth300');
		} else {
			if (!empty($objectsrc) && !empty($objectsrc->fk_project)) {
				$proj = new Project($db);
				$proj->fetch($objectsrc->fk_project);
				$morehtmlref .= $proj->getNomUrl(1);
				if ($proj->title) {
					$morehtmlref .= '<span class="opacitymedium"> - '.dol_escape_htmltag($proj->title).'</span>';
				}
			}
		}
	}
	$morehtmlref .= '</div>';


	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);


	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';

	print '<table class="border tableforfield centpercent">';

	// Linked documents
	if (!empty($typeobject) && $typeobject == 'commande' && $object->origin_object->id && isModEnabled('order')) {
		print '<tr><td>';
		print $langs->trans("RefOrder").'</td>';
		print '<td>';
		print $objectsrc->getNomUrl(1, 'commande');
		print "</td>\n";
		print '</tr>';
	}
	if (!empty($typeobject) && $typeobject == 'propal' && $object->origin_object->id && isModEnabled("propal")) {
		print '<tr><td>';
		print $langs->trans("RefProposal").'</td>';
		print '<td>';
		print $objectsrc->getNomUrl(1, 'expedition');
		print "</td>\n";
		print '</tr>';
	}

	// Date creation
	print '<tr><td class="titlefieldmiddle">'.$langs->trans("DateCreation").'</td>';
	print '<td>'.dol_print_date($object->date_creation, "dayhour")."</td>\n";
	print '</tr>';

	// Delivery date planned
	print '<tr><td height="10">';
	print '<table class="nobordernopadding centpercent"><tr><td>';
	print $langs->trans('DateDeliveryPlanned');
	print '</td>';
	if ($action != 'editdate_livraison') {
		print '<td class="right"><a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?action=editdate_livraison&token='.newToken().'&id='.$object->id.'">'.img_edit($langs->trans('SetDeliveryDate'), 1).'</a></td>';
	}
	print '</tr></table>';
	print '</td><td>';
	if ($action == 'editdate_livraison') {
		print '<form name="setdate_livraison" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" method="post">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="setdate_livraison">';
		print $form->selectDate($object->date_delivery ? $object->date_delivery : -1, 'liv_', 1, 1, 0, "setdate_livraison", 1, 0);
		print '<input type="submit" class="button button-edit smallpaddingimp" value="'.$langs->trans('Modify').'">';
		print '</form>';
	} else {
		print $object->date_delivery ? dol_print_date($object->date_delivery, 'dayhour') : '&nbsp;';
	}
	print '</td>';
	print '</tr>';

	// Delivery sending date
	print '<tr><td height="10">';
	print '<table class="nobordernopadding centpercent"><tr><td>';
	print $langs->trans('DateShipping');
	print '</td>';
	if ($action != 'editdate_shipping') {
		print '<td class="right"><a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?action=editdate_shipping&token='.newToken().'&id='.$object->id.'">'.img_edit($langs->trans('SetShippingDate'), 1).'</a></td>';
	}
	print '</tr></table>';
	print '</td><td>';
	if ($action == 'editdate_shipping') {
		print '<form name="setdate_shipping" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" method="post">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="setdate_shipping">';
		print $form->selectDate($object->date_shipping ? $object->date_shipping : -1, 'ship_', 1, 1, 0, "setdate_shipping", 1, 0);
		print '<input type="submit" class="button button-edit smallpaddingimp" value="'.$langs->trans('Modify').'">';
		print '</form>';
	} else {
		print $object->date_shipping ? dol_print_date($object->date_shipping, 'dayhour') : '&nbsp;';
	}
	print '</td>';
	print '</tr>';

	// Weight
	print '<tr><td>';
	print $form->editfieldkey("Weight", 'trueWeight', $object->trueWeight, $object, $user->hasRight('expedition', 'creer'));
	print '</td><td>';

	if ($action == 'edittrueWeight') {
		print '<form name="settrueweight" action="'.$_SERVER["PHP_SELF"].'" method="post">';
		print '<input name="action" value="settrueWeight" type="hidden">';
		print '<input name="id" value="'.$object->id.'" type="hidden">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input id="trueWeight" name="trueWeight" value="'.$object->trueWeight.'" type="text" class="width50 valignmiddle">';
		print $formproduct->selectMeasuringUnits("weight_units", "weight", $object->weight_units, 0, 2, 'maxwidth125 valignmiddle');
		print ' <input class="button smallpaddingimp valignmiddle" name="modify" value="'.$langs->trans("Modify").'" type="submit">';
		print ' <input class="button button-cancel smallpaddingimp valignmiddle" name="cancel" value="'.$langs->trans("Cancel").'" type="submit">';
		print '</form>';
	} else {
		print $object->trueWeight;
		print ($object->trueWeight && $object->weight_units != '') ? ' '.measuringUnitString(0, "weight", $object->weight_units) : '';
	}

	// Calculated
	if ($totalWeight > 0) {
		if (!empty($object->trueWeight)) {
			print ' ('.$langs->trans("SumOfProductWeights").': ';
		}
		print showDimensionInBestUnit($totalWeight, 0, "weight", $langs, getDolGlobalInt('MAIN_WEIGHT_DEFAULT_ROUND', -1), isset($conf->global->MAIN_WEIGHT_DEFAULT_UNIT) ? $conf->global->MAIN_WEIGHT_DEFAULT_UNIT : 'no');
		if (!empty($object->trueWeight)) {
			print ')';
		}
	}
	print '</td></tr>';

	// Width
	print '<tr><td>'.$form->editfieldkey("Width", 'trueWidth', $object->trueWidth, $object, $user->hasRight('expedition', 'creer')).'</td><td>';
	print $form->editfieldval("Width", 'trueWidth', $object->trueWidth, $object, $user->hasRight('expedition', 'creer'));
	print ($object->trueWidth && $object->width_units != '') ? ' '.measuringUnitString(0, "size", $object->width_units) : '';
	print '</td></tr>';

	// Height
	print '<tr><td>'.$form->editfieldkey("Height", 'trueHeight', $object->trueHeight, $object, $user->hasRight('expedition', 'creer')).'</td><td>';
	if ($action == 'edittrueHeight') {
		print '<form name="settrueHeight" action="'.$_SERVER["PHP_SELF"].'" method="post">';
		print '<input name="action" value="settrueHeight" type="hidden">';
		print '<input name="id" value="'.$object->id.'" type="hidden">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input id="trueHeight" name="trueHeight" value="'.$object->trueHeight.'" type="text" class="width50">';
		print $formproduct->selectMeasuringUnits("size_units", "size", $object->size_units, 0, 2);
		print ' <input class="button smallpaddingimp" name="modify" value="'.$langs->trans("Modify").'" type="submit">';
		print ' <input class="button button-cancel smallpaddingimp" name="cancel" value="'.$langs->trans("Cancel").'" type="submit">';
		print '</form>';
	} else {
		print $object->trueHeight;
		print ($object->trueHeight && $object->height_units != '') ? ' '.measuringUnitString(0, "size", $object->height_units) : '';
	}

	print '</td></tr>';

	// Depth
	print '<tr><td>'.$form->editfieldkey("Depth", 'trueDepth', $object->trueDepth, $object, $user->hasRight('expedition', 'creer')).'</td><td>';
	print $form->editfieldval("Depth", 'trueDepth', $object->trueDepth, $object, $user->hasRight('expedition', 'creer'));
	print ($object->trueDepth && $object->depth_units != '') ? ' '.measuringUnitString(0, "size", $object->depth_units) : '';
	print '</td></tr>';

	// Volume
	print '<tr><td>';
	print $langs->trans("Volume");
	print '</td>';
	print '<td>';
	$calculatedVolume = 0;
	$volumeUnit = 0;
	if ($object->trueWidth && $object->trueHeight && $object->trueDepth) {
		$calculatedVolume = ($object->trueWidth * $object->trueHeight * $object->trueDepth);
		$volumeUnit = $object->size_units * 3;
	}
	// If sending volume not defined we use sum of products
	if ($calculatedVolume > 0) {
		if ($volumeUnit < 50) {
			print showDimensionInBestUnit($calculatedVolume, $volumeUnit, "volume", $langs, getDolGlobalInt('MAIN_VOLUME_DEFAULT_ROUND', -1), getDolGlobalString('MAIN_VOLUME_DEFAULT_UNIT', 'no'));
		} else {
			print $calculatedVolume.' '.measuringUnitString(0, "volume", (string) $volumeUnit);
		}
	}
	if ($totalVolume > 0) {
		if ($calculatedVolume) {
			print ' ('.$langs->trans("SumOfProductVolumes").': ';
		}
		print showDimensionInBestUnit($totalVolume, 0, "volume", $langs, getDolGlobalInt('MAIN_VOLUME_DEFAULT_ROUND', -1), getDolGlobalString('MAIN_VOLUME_DEFAULT_UNIT', 'no'));
		//if (empty($calculatedVolume)) print ' ('.$langs->trans("Calculated").')';
		if ($calculatedVolume) {
			print ')';
		}
	}
	print "</td>\n";
	print '</tr>';

	// Other attributes
	//$cols = 2;
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_view.tpl.php';

	print '</table>';

	print '</div>';
	print '<div class="fichehalfright">';
	print '<div class="underbanner clearboth"></div>';

	print '<table class="border centpercent tableforfield">';

	// Sending method
	print '<tr><td>';
	print '<table class="nobordernopadding centpercent"><tr><td>';
	print $langs->trans('SendingMethod');
	print '</td>';

	if ($action != 'editshipping_method_id' && $permissiontoadd) {
		print '<td class="right"><a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?action=editshipping_method_id&token='.newToken().'&id='.$object->id.'">'.img_edit($langs->trans('SetSendingMethod'), 1).'</a></td>';
	}
	print '</tr></table>';
	print '</td><td>';
	if ($action == 'editshipping_method_id') {
		print '<form name="setshipping_method_id" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" method="post">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="setshipping_method_id">';
		$object->fetch_delivery_methods();
		print $form->selectarray("shipping_method_id", $object->meths, $object->shipping_method_id, 1, 0, 0, "", 1);
		if ($user->admin) {
			print info_admin($langs->trans("YouCanChangeValuesForThisListFromDictionarySetup"), 1);
		}
		print '<input type="submit" class="button button-edit smallpaddingimp" value="'.$langs->trans('Modify').'">';
		print '</form>';
	} else {
		if ($object->shipping_method_id > 0) {
			// Get code using getLabelFromKey
			$code = $langs->getLabelFromKey($db, $object->shipping_method_id, 'c_shipment_mode', 'rowid', 'code');
			print $langs->trans("SendingMethod".strtoupper($code));
		}
	}
	print '</td>';
	print '</tr>';

	// Tracking Number
	print '<tr><td class="titlefieldmiddle">'.$form->editfieldkey("TrackingNumber", 'tracking_number', $object->tracking_number, $object, $user->hasRight('expedition', 'creer')).'</td><td>';
	print $form->editfieldval("TrackingNumber", 'tracking_number', $object->tracking_url, $object, $user->hasRight('expedition', 'creer'), 'safehtmlstring', $object->tracking_number);
	print '</td></tr>';

	// Incoterms
	if (isModEnabled('incoterm')) {
		print '<tr><td>';
		print '<table class="nobordernopadding centpercent"><tr><td>';
		print $langs->trans('IncotermLabel');
		print '<td><td class="right">';
		if ($permissiontoadd) {
			print '<a class="editfielda" href="'.DOL_URL_ROOT.'/expedition/card.php?id='.$object->id.'&action=editincoterm&token='.newToken().'">'.img_edit().'</a>';
		} else {
			print '&nbsp;';
		}
		print '</td></tr></table>';
		print '</td>';
		print '<td>';
		if ($action != 'editincoterm') {
			print $form->textwithpicto($object->display_incoterms(), $object->label_incoterms, 1);
		} else {
			print $form->select_incoterms((!empty($object->fk_incoterms) ? $object->fk_incoterms : ''), (!empty($object->location_incoterms) ? $object->location_incoterms : ''), $_SERVER['PHP_SELF'].'?id='.$object->id);
		}
		print '</td></tr>';
	}

	// Other attributes
	$parameters = array();
	$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;

	print "</table>";

	print '</div>';
	print '</div>';

	print '<div class="clearboth"></div>';


	// Lines of products

	if ($action == 'editline') {
		print '	<form name="updateline" id="updateline" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&amp;lineid='.$line_id.'" method="POST">
		<input type="hidden" name="token" value="' . newToken().'">
		<input type="hidden" name="action" value="updateline">
		<input type="hidden" name="mode" value="">
		<input type="hidden" name="id" value="' . $object->id.'">
		';
	}
	print '<br>';

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent" id="tablelines" >';
	print '<thead>';
	print '<tr class="liste_titre">';
	// Adds a line numbering column
	if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER')) {
		print '<td width="5" class="center linecolnum">&nbsp;</td>';
	}
	// Product/Service
	print '<td  class="linecoldescription" >'.$langs->trans("Products").'</td>';
	// Qty
	print '<td class="center linecolqty">'.$langs->trans("QtyOrdered").'</td>';
	if ($origin && $origin_id > 0) {
		print '<td class="center linecolqtyinothershipments">'.$langs->trans("QtyInOtherShipments").'</td>';
	}
	if ($action == 'editline') {
		$editColspan = 3;
		if (!isModEnabled('stock')) {
			$editColspan--;
		}
		if (empty($conf->productbatch->enabled)) {
			$editColspan--;
		}
		print '<td class="center linecoleditlineotherinfo" colspan="'.$editColspan.'">';
		if ($object->status <= 1) {
			print $langs->trans("QtyToShip");
		} else {
			print $langs->trans("QtyShipped");
		}
		if (isModEnabled('stock')) {
			print ' - '.$langs->trans("WarehouseSource");
		}
		if (isModEnabled('productbatch')) {
			print ' - '.$langs->trans("Batch");
		}
		print '</td>';
	} else {
		if ($object->status <= 1) {
			print '<td class="center linecolqtytoship">'.$langs->trans("QtyToShip").'</td>';
		} else {
			print '<td class="center linecolqtyshipped">'.$langs->trans("QtyShipped").'</td>';
		}
		if (isModEnabled('stock')) {
			print '<td class="left linecolwarehousesource">'.$langs->trans("WarehouseSource").'</td>';
		}

		if (isModEnabled('productbatch')) {
			print '<td class="left linecolbatch">'.$langs->trans("Batch").'</td>';
		}
	}
	print '<td class="center linecolweight">'.$langs->trans("CalculatedWeight").'</td>';
	print '<td class="center linecolvolume">'.$langs->trans("CalculatedVolume").'</td>';
	//print '<td class="center">'.$langs->trans("Size").'</td>';
	if ($object->status == 0) {
		print '<td class="linecoledit"></td>';
		print '<td class="linecoldelete" width="10"></td>';
	}
	print "</tr>\n";
	print '</thead>';

	$outputlangs = $langs;

	if (getDolGlobalInt('MAIN_MULTILANGS') && getDolGlobalString('PRODUIT_TEXTS_IN_THIRDPARTY_LANGUAGE')) {
		$object->fetch_thirdparty();
		$newlang = '';
		if (empty($newlang) && GETPOST('lang_id', 'aZ09')) {
			$newlang = GETPOST('lang_id', 'aZ09');
		}
		if (empty($newlang)) {
			$newlang = $object->thirdparty->default_lang;
		}
		if (!empty($newlang)) {
			$outputlangs = new Translate("", $conf);
			$outputlangs->setDefaultLang($newlang);
		}
	}

	// Get list of products already sent for same source object into $alreadysent
	$alreadysent = array();
	if ($origin && $origin_id > 0) {
		$sql = "SELECT obj.rowid, obj.fk_product, obj.label, obj.description, obj.product_type as fk_product_type, obj.qty as qty_asked, obj.fk_unit, obj.date_start, obj.date_end";
		$sql .= ", ed.rowid as shipmentline_id, ed.qty as qty_shipped, ed.fk_expedition as expedition_id, ed.fk_elementdet, ed.fk_entrepot";
		$sql .= ", e.rowid as shipment_id, e.ref as shipment_ref, e.date_creation, e.date_valid, e.date_delivery, e.date_expedition";
		//if (getDolGlobalInt('MAIN_SUBMODULE_DELIVERY')) $sql .= ", l.rowid as livraison_id, l.ref as livraison_ref, l.date_delivery, ld.qty as qty_received";
		$sql .= ', p.label as product_label, p.ref, p.fk_product_type, p.rowid as prodid, p.tosell as product_tosell, p.tobuy as product_tobuy, p.tobatch as product_tobatch';
		$sql .= ', p.description as product_desc';
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet as ed";
		$sql .= ", ".MAIN_DB_PREFIX."expedition as e";
		$sql .= ", ".MAIN_DB_PREFIX.$origin."det as obj";
		//if (getDolGlobalInt('MAIN_SUBMODULE_DELIVERY')) $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."delivery as l ON l.fk_expedition = e.rowid LEFT JOIN ".MAIN_DB_PREFIX."deliverydet as ld ON ld.fk_delivery = l.rowid  AND obj.rowid = ld.fk_origin_line";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON obj.fk_product = p.rowid";
		$sql .= " WHERE e.entity IN (".getEntity('expedition').")";
		$sql .= " AND obj.fk_".$origin." = ".((int) $origin_id);
		$sql .= " AND obj.rowid = ed.fk_elementdet";
		$sql .= " AND ed.fk_expedition = e.rowid";
		//if ($filter) $sql.= $filter;
		$sql .= " ORDER BY obj.fk_product";

		dol_syslog("expedition/card.php get list of shipment lines", LOG_DEBUG);
		$resql = $db->query($sql);
		if ($resql) {
			$num = $db->num_rows($resql);
			$i = 0;

			while ($i < $num) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					// $obj->rowid is rowid in $origin."det" table
					$alreadysent[$obj->rowid][$obj->shipmentline_id] = array(
						'shipment_ref' => $obj->shipment_ref, 'shipment_id' => $obj->shipment_id, 'warehouse' => $obj->fk_entrepot, 'qty_shipped' => $obj->qty_shipped,
						'product_tosell' => $obj->product_tosell, 'product_tobuy' => $obj->product_tobuy, 'product_tobatch' => $obj->product_tobatch,
						'date_valid' => $db->jdate($obj->date_valid), 'date_delivery' => $db->jdate($obj->date_delivery));
				}
				$i++;
			}
		}
		//var_dump($alreadysent);
	}

	print '<tbody>';

	// Loop on each product to send/sent
	for ($i = 0; $i < $num_prod; $i++) {
		$parameters = array('i' => $i, 'line' => $lines[$i], 'line_id' => $line_id, 'num' => $num_prod, 'alreadysent' => $alreadysent, 'editColspan' => !empty($editColspan) ? $editColspan : 0, 'outputlangs' => $outputlangs);
		$reshook = $hookmanager->executeHooks('printObjectLine', $parameters, $object, $action);
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}

		if (empty($reshook)) {
			print '<!-- origin line id = '.$lines[$i]->origin_line_id.' -->'; // id of order line
			print '<tr class="oddeven" id="row-'.$lines[$i]->id.'" data-id="'.$lines[$i]->id.'" data-element="'.$lines[$i]->element.'" >';

			// #
			if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER')) {
				print '<td class="center linecolnum">'.($i + 1).'</td>';
			}

			// Predefined product or service
			if ($lines[$i]->fk_product > 0) {
				// Define output language
				if (getDolGlobalInt('MAIN_MULTILANGS') && getDolGlobalString('PRODUIT_TEXTS_IN_THIRDPARTY_LANGUAGE')) {
					$prod = new Product($db);
					$prod->fetch($lines[$i]->fk_product);
					$label = (!empty($prod->multilangs[$outputlangs->defaultlang]["label"])) ? $prod->multilangs[$outputlangs->defaultlang]["label"] : $lines[$i]->product_label;
				} else {
					$label = (!empty($lines[$i]->label) ? $lines[$i]->label : $lines[$i]->product_label);
				}

				print '<td class="linecoldescription">';

				// Show product and description
				$product_static->type = $lines[$i]->fk_product_type;
				$product_static->id = $lines[$i]->fk_product;
				$product_static->ref = $lines[$i]->ref;
				$product_static->status = $lines[$i]->product_tosell;
				$product_static->status_buy = $lines[$i]->product_tobuy;
				$product_static->status_batch = $lines[$i]->product_tobatch;

				$product_static->weight = $lines[$i]->weight;
				$product_static->weight_units = $lines[$i]->weight_units;
				$product_static->length = $lines[$i]->length;
				$product_static->length_units = $lines[$i]->length_units;
				$product_static->width = !empty($lines[$i]->width) ? $lines[$i]->width : 0;
				$product_static->width_units = !empty($lines[$i]->width_units) ? $lines[$i]->width_units : 0;
				$product_static->height = !empty($lines[$i]->height) ? $lines[$i]->height : 0;
				$product_static->height_units = !empty($lines[$i]->height_units) ? $lines[$i]->height_units : 0;
				$product_static->surface = $lines[$i]->surface;
				$product_static->surface_units = $lines[$i]->surface_units;
				$product_static->volume = $lines[$i]->volume;
				$product_static->volume_units = $lines[$i]->volume_units;

				$text = $product_static->getNomUrl(1);
				$text .= ' - '.$label;
				$description = (getDolGlobalInt('PRODUIT_DESC_IN_FORM_ACCORDING_TO_DEVICE') ? '' : dol_htmlentitiesbr($lines[$i]->description));
				print $form->textwithtooltip($text, $description, 3, 0, '', $i);
				print_date_range(!empty($lines[$i]->date_start) ? $lines[$i]->date_start : '', !empty($lines[$i]->date_end) ? $lines[$i]->date_end : '');
				if (getDolGlobalInt('PRODUIT_DESC_IN_FORM_ACCORDING_TO_DEVICE')) {
					print (!empty($lines[$i]->description) && $lines[$i]->description != $lines[$i]->product) ? '<br>'.dol_htmlentitiesbr($lines[$i]->description) : '';
				}
				print "</td>\n";
			} else {
				print '<td class="linecoldescription" >';
				if ($lines[$i]->product_type == Product::TYPE_SERVICE) {
					$text = img_object($langs->trans('Service'), 'service');
				} else {
					$text = img_object($langs->trans('Product'), 'product');
				}

				if (!empty($lines[$i]->label)) {
					$text .= ' <strong>'.$lines[$i]->label.'</strong>';
					print $form->textwithtooltip($text, $lines[$i]->description, 3, 0, '', $i);
				} else {
					print $text.' '.nl2br($lines[$i]->description);
				}

				print_date_range($lines[$i]->date_start, $lines[$i]->date_end);
				print "</td>\n";
			}

			$unit_order = '';
			if (getDolGlobalString('PRODUCT_USE_UNITS')) {
				$unit_order = measuringUnitString($lines[$i]->fk_unit);
			}

			// Qty ordered
			print '<td class="center linecolqty">'.$lines[$i]->qty_asked.' '.$unit_order.'</td>';

			// Qty in other shipments (with shipment and warehouse used)
			if ($origin && $origin_id > 0) {
				print '<td class="linecolqtyinothershipments center nowrap">';
				$htmltooltip = '';
				$qtyalreadysent = 0;
				foreach ($alreadysent as $key => $val) {
					if ($lines[$i]->fk_elementdet == $key) {
						$j = 0;
						foreach ($val as $shipmentline_id => $shipmentline_var) {
							if ($shipmentline_var['shipment_id'] == $lines[$i]->fk_expedition) {
								continue; // We want to show only "other shipments"
							}

							$j++;
							if ($j > 1) {
								$htmltooltip .= '<br>';
							}
							$shipment_static->fetch($shipmentline_var['shipment_id']);
							$htmltooltip .= $shipment_static->getNomUrl(1, '', 0, 0, 1);
							$htmltooltip .= ' - '.$shipmentline_var['qty_shipped'];
							$htmltooltip .= ' - '.$langs->trans("DateValidation").' : '.(empty($shipmentline_var['date_valid']) ? $langs->trans("Draft") : dol_print_date($shipmentline_var['date_valid'], 'dayhour'));
							/*if (isModEnabled('stock') && $shipmentline_var['warehouse'] > 0) {
								$warehousestatic->fetch($shipmentline_var['warehouse']);
								$htmltext .= '<br>'.$langs->trans("FromLocation").' : '.$warehousestatic->getNomUrl(1, '', 0, 1);
							}*/
							//print ' '.$form->textwithpicto('', $htmltext, 1);

							$qtyalreadysent += $shipmentline_var['qty_shipped'];
						}
						if ($j) {
							$htmltooltip = $langs->trans("QtyInOtherShipments").'...<br><br>'.$htmltooltip.'<br><input type="submit" name="dummyhiddenbuttontogetfocus" style="display:none" autofocus>';
						}
					}
				}
				print $form->textwithpicto($qtyalreadysent, $htmltooltip, 1, 'info', '', 0, 3, 'tooltip'.$lines[$i]->id);
				print '</td>';
			}

			if ($action == 'editline' && $lines[$i]->id == $line_id) {
				// edit mode
				print '<td colspan="'.$editColspan.'" class="center"><table class="nobordernopadding centpercent">';
				if (is_array($lines[$i]->detail_batch) && count($lines[$i]->detail_batch) > 0) {
					print '<!-- case edit 1 -->';
					$line = new ExpeditionLigne($db);
					foreach ($lines[$i]->detail_batch as $detail_batch) {
						print '<tr>';
						// Qty to ship or shipped
					$readonly_edit_attr = '';
					$value_edit_qty = $detail_batch->qty;
					if ($lines[$i]->qty_asked <= $qtyalreadysent) {
						$readonly_edit_attr = ' readonly="readonly"';
						$value_edit_qty = 0;
					}
					print '<td><input class="qtyl right" name="qtyl'.$detail_batch->fk_expeditiondet.'_'.$detail_batch->id.'" id="qtyl'.$line_id.'_'.$detail_batch->id.'" type="text" size="4" value="'.$value_edit_qty.'"'.$readonly_edit_attr.'></td>';
						// Batch number management
						if ($lines[$i]->entrepot_id == 0) {
							// only show lot numbers from src warehouse when shipping from multiple warehouses
							$line->fetch($detail_batch->fk_expeditiondet);
						}
						$entrepot_id = !empty($detail_batch->entrepot_id) ? $detail_batch->entrepot_id : $lines[$i]->entrepot_id;
						print '<td>'.$formproduct->selectLotStock($detail_batch->fk_origin_stock, 'batchl'.$detail_batch->fk_expeditiondet.'_'.$detail_batch->fk_origin_stock, '', 1, 0, $lines[$i]->fk_product, $entrepot_id).'</td>';
						print '</tr>';
					}
					// add a 0 qty lot row to be able to add a lot
					print '<tr>';
					// Qty to ship or shipped
					$readonly_edit_attr_new_batch = '';
					if ($lines[$i]->qty_asked <= $qtyalreadysent) {
						$readonly_edit_attr_new_batch = ' readonly="readonly"';
					}
					print '<td><input class="qtyl" name="qtyl'.$line_id.'_0" id="qtyl'.$line_id.'_0" type="text" size="4" value="0"'.$readonly_edit_attr_new_batch.'></td>';
					// Batch number management
					print '<td>'.$formproduct->selectLotStock('', 'batchl'.$line_id.'_0', '', 1, ($lines[$i]->qty_asked <= $qtyalreadysent ? 1 : 0), $lines[$i]->fk_product).'</td>';
					print '</tr>';
				} elseif (isModEnabled('stock')) {
					if ($lines[$i]->fk_product > 0) {
						if ($lines[$i]->entrepot_id > 0) {
							print '<!-- case edit 2 -->';
							print '<tr>';
							// Qty to ship or shipped
							$readonly_edit_attr_stock_single_wh = '';
							$value_edit_qty_stock_single_wh = $lines[$i]->qty_shipped;
							if ($lines[$i]->qty_asked <= $qtyalreadysent) {
								$readonly_edit_attr_stock_single_wh = ' readonly="readonly"';
								$value_edit_qty_stock_single_wh = 0;
							}
							print '<td><input class="qtyl right" name="qtyl'.$line_id.'" id="qtyl'.$line_id.'" type="text" size="4" value="'.$value_edit_qty_stock_single_wh.'"'.$readonly_edit_attr_stock_single_wh.'>'.$unit_order.'</td>';
							// Warehouse source
							print '<td>'.$formproduct->selectWarehouses($lines[$i]->entrepot_id, 'entl'.$line_id, '', 1, ($lines[$i]->qty_asked <= $qtyalreadysent ? 1 : 0), $lines[$i]->fk_product, '', 1, 0, array(), 'minwidth200').'</td>';
							// Batch number management
							print '<td>';
							if (isModEnabled('productbatch')) {
								print ' - '.$langs->trans("NA");
							}
							print '</td>';
							print '</tr>';
						} elseif (count($lines[$i]->details_entrepot) > 1) {
							print '<!-- case edit 3 -->';
							foreach ($lines[$i]->details_entrepot as $detail_entrepot) {
								print '<tr>';
								// Qty to ship or shipped
									$readonly_edit_attr_stock_multi_wh = '';
									$value_edit_qty_stock_multi_wh = $detail_entrepot->qty_shipped;
									if ($lines[$i]->qty_asked <= $qtyalreadysent) {
										$readonly_edit_attr_stock_multi_wh = ' readonly="readonly"';
										$value_edit_qty_stock_multi_wh = 0;
									}
									print '<td><input class="qtyl right" name="qtyl'.$detail_entrepot->line_id.'" id="qtyl'.$detail_entrepot->line_id.'" type="text" size="4" value="'.$value_edit_qty_stock_multi_wh.'"'.$readonly_edit_attr_stock_multi_wh.'>'.$unit_order.'</td>';
								// Warehouse source
									print '<td>'.$formproduct->selectWarehouses($detail_entrepot->entrepot_id, 'entl'.$detail_entrepot->line_id, '', 1, ($lines[$i]->qty_asked <= $qtyalreadysent ? 1 : 0), $lines[$i]->fk_product, '', 1, 0, array(), 'minwidth200').'</td>';
								// Batch number management
								print '<td>';
								if (isModEnabled('productbatch')) {
									print ' - '.$langs->trans("NA");
								}
								print '</td>';
								print '</tr>';
							}
						} elseif ($lines[$i]->product_type == Product::TYPE_SERVICE && getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES')) {
							print '<!-- case edit 4 -->';
							print '<tr>';
							// Qty to ship or shipped
							$readonly_edit_attr_stock_service = '';
							$value_edit_qty_stock_service = $lines[$i]->qty_shipped;
							if ($lines[$i]->qty_asked <= $qtyalreadysent) {
								$readonly_edit_attr_stock_service = ' readonly="readonly"';
								$value_edit_qty_stock_service = 0;
							}
							print '<td><input class="qtyl right" name="qtyl'.$line_id.'" id="qtyl'.$line_id.'" type="text" size="4" value="'.$value_edit_qty_stock_service.'"'.$readonly_edit_attr_stock_service.'></td>';
							print '<td><span class="opacitymedium">('.$langs->trans("Service").')</span></td>';
							print '<td></td>';
							print '</tr>';
						} else {
							print '<!-- case edit 5 -->';
							print '<tr><td colspan="3">'.$langs->trans("ErrorStockIsNotEnough").'</td></tr>';
						}
					} else {
						print '<!-- case edit 6 -->';
						print '<tr>';
						// Qty to ship or shipped
						$readonly_edit_attr_stock_no_fkprod = '';
						$value_edit_qty_stock_no_fkprod = $lines[$i]->qty_shipped;
						if ($lines[$i]->qty_asked <= $qtyalreadysent) {
							$readonly_edit_attr_stock_no_fkprod = ' readonly="readonly"';
							$value_edit_qty_stock_no_fkprod = 0;
						}
						print '<td><input class="qtyl right" name="qtyl'.$line_id.'" id="qtyl'.$line_id.'" type="text" size="4" value="'.$value_edit_qty_stock_no_fkprod.'"'.$readonly_edit_attr_stock_no_fkprod.'>'.$unit_order.'</td>';
						// Warehouse source
						print '<td></td>';
						// Batch number management
						print '<td></td>';
						print '</tr>';
					}
				} elseif (!isModEnabled('stock') && empty($conf->productbatch->enabled)) { // both product batch and stock are not activated.
					print '<!-- case edit 7 -->';
					print '<tr>';
					// Qty to ship or shipped
					$readonly_edit_attr_no_stock_module = '';
					$value_edit_qty_no_stock_module = $lines[$i]->qty_shipped;
					if ($lines[$i]->qty_asked <= $qtyalreadysent) {
						$readonly_edit_attr_no_stock_module = ' readonly="readonly"';
						$value_edit_qty_no_stock_module = 0;
					}
					print '<td><input class="qtyl right" name="qtyl'.$line_id.'" id="qtyl'.$line_id.'" type="text" size="4" value="'.$value_edit_qty_no_stock_module.'"'.$readonly_edit_attr_no_stock_module.'></td>';
					// Warehouse source
					print '<td></td>';
					// Batch number management
					print '<td></td>';
					print '</tr>';
				}

				print '</table></td>';
			} else {
				// Qty to ship or shipped
				print '<td class="linecolqtytoship center">'.$lines[$i]->qty_shipped.' '.$unit_order.'</td>';

				// Warehouse source
				if (isModEnabled('stock')) {
					print '<td class="linecolwarehousesource tdoverflowmax200">';
					if ($lines[$i]->product_type == Product::TYPE_SERVICE && getDolGlobalString('SHIPMENT_SUPPORTS_SERVICES')) {
						print '<span class="opacitymedium">('.$langs->trans("Service").')</span>';
					} elseif ($lines[$i]->entrepot_id > 0) {
						$entrepot = new Entrepot($db);
						$entrepot->fetch($lines[$i]->entrepot_id);
						print $entrepot->getNomUrl(1);
					} elseif (count($lines[$i]->details_entrepot) > 1) {
						$detail = '';
						foreach ($lines[$i]->details_entrepot as $detail_entrepot) {
							if ($detail_entrepot->entrepot_id > 0) {
								$entrepot = new Entrepot($db);
								$entrepot->fetch($detail_entrepot->entrepot_id);
								$detail .= $langs->trans("DetailWarehouseFormat", $entrepot->label, $detail_entrepot->qty_shipped).'<br>';
							}
						}
						print $form->textwithtooltip(img_picto('', 'object_stock').' '.$langs->trans("DetailWarehouseNumber"), $detail);
					}
					print '</td>';
				}

				// Batch number management
				if (isModEnabled('productbatch')) {
					if (isset($lines[$i]->detail_batch)) {
						print '<!-- Detail of lot -->';
						print '<td class="linecolbatch">';
						if ($lines[$i]->product_tobatch) {
							$detail = '';
							foreach ($lines[$i]->detail_batch as $dbatch) {	// $dbatch is instance of ExpeditionLineBatch
								$detail .= $langs->trans("Batch").': '.$dbatch->batch;
								if (!getDolGlobalString('PRODUCT_DISABLE_SELLBY')) {
									$detail .= ' - '.$langs->trans("SellByDate").': '.dol_print_date($dbatch->sellby, "day");
								}
								if (!getDolGlobalString('PRODUCT_DISABLE_EATBY')) {
									$detail .= ' - '.$langs->trans("EatByDate").': '.dol_print_date($dbatch->eatby, "day");
								}
								$detail .= ' - '.$langs->trans("Qty").': '.$dbatch->qty;
								$detail .= '<br>';
							}
							print $form->textwithtooltip(img_picto('', 'object_barcode').' '.$langs->trans("DetailBatchNumber"), $detail);
						} else {
							print $langs->trans("NA");
						}
						print '</td>';
					} else {
						print '<td class="linecolbatch" ></td>';
					}
				}
			}

			// Weight
			print '<td class="center linecolweight">';
			if ($lines[$i]->fk_product_type == Product::TYPE_PRODUCT) {
				print $lines[$i]->weight * $lines[$i]->qty_shipped.' '.measuringUnitString(0, "weight", $lines[$i]->weight_units);
			} else {
				print '&nbsp;';
			}
			print '</td>';

			// Volume
			print '<td class="center linecolvolume">';
			if ($lines[$i]->fk_product_type == Product::TYPE_PRODUCT) {
				print $lines[$i]->volume * $lines[$i]->qty_shipped.' '.measuringUnitString(0, "volume", $lines[$i]->volume_units);
			} else {
				print '&nbsp;';
			}
			print '</td>';

			// Size
			//print '<td class="center">'.$lines[$i]->volume*$lines[$i]->qty_shipped.' '.measuringUnitString(0, "volume", $lines[$i]->volume_units).'</td>';

			if ($action == 'editline' && $lines[$i]->id == $line_id) {
				print '<td class="center" colspan="2" valign="middle">';
				print '<input type="submit" class="button button-save" id="savelinebutton marginbottomonly" name="save" value="'.$langs->trans("Save").'"><br>';
				print '<input type="submit" class="button button-cancel" id="cancellinebutton" name="cancel" value="'.$langs->trans("Cancel").'"><br>';
				print '</td>';
			} elseif ($object->status == Expedition::STATUS_DRAFT) {
				// edit-delete buttons
				print '<td class="linecoledit center">';
				print '<a class="editfielda reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=editline&token='.newToken().'&lineid='.$lines[$i]->id.'">'.img_edit().'</a>';
				print '</td>';
				print '<td class="linecoldelete" width="10">';
				print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=deleteline&token='.newToken().'&lineid='.$lines[$i]->id.'">'.img_delete().'</a>';
				print '</td>';

				// Display lines extrafields
				if (!empty($rowExtrafieldsStart)) {
					print $rowExtrafieldsStart;
					print $rowExtrafieldsView;
					print $rowEnd;
				}
			}
			print "</tr>";

			// Display lines extrafields.
			// $line is a line of shipment
			if (!empty($extrafields)) {
				$colspan = 6;
				if ($origin && $origin_id > 0) {
					$colspan++;
				}
				if (isModEnabled('productbatch')) {
					$colspan++;
				}
				if (isModEnabled('stock')) {
					$colspan++;
				}

				$line = $lines[$i];
				$line->fetch_optionals();

				// TODO Show all in same line by setting $display_type = 'line'
				if ($action == 'editline' && $line->id == $line_id) {
					print $lines[$i]->showOptionals($extrafields, 'edit', array('colspan' => $colspan), !empty($indiceAsked) ? $indiceAsked : '', '', 0, 'card');
				} else {
					print $lines[$i]->showOptionals($extrafields, 'view', array('colspan' => $colspan), !empty($indiceAsked) ? $indiceAsked : '', '', 0, 'card');
				}
			}
		}
	}

	// TODO Show also lines ordered but not delivered

	if (empty($num_prod)) {
		print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoLineGoOnTabToAddSome", $langs->transnoentitiesnoconv("ShipmentDistribution")).'</span></td></tr>';
	}

	print "</table>\n";
	print '</tbody>';
	print '</div>';


	print dol_get_fiche_end();


	$object->fetchObjectLinked($object->id, $object->element);


	/*
	 *    Boutons actions
	 */

	if (($user->socid == 0) && ($action != 'presend')) {
		print '<div class="tabsAction">';

		$parameters = array();
		$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been
		// modified by hook
		if (empty($reshook)) {
			if ($object->status == Expedition::STATUS_DRAFT && $num_prod > 0) {
				if ((!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('expedition', 'creer'))
				 || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('expedition', 'shipping_advance', 'validate'))) {
					print dolGetButtonAction('', $langs->trans('Validate'), 'default', $_SERVER["PHP_SELF"].'?action=valid&token='.newToken().'&id='.$object->id, '');
				} else {
					print dolGetButtonAction($langs->trans('NotAllowed'), $langs->trans('Validate'), 'default', $_SERVER['PHP_SELF']. '#', '', false);
				}
			}

			// 0=draft, 1=validated/delivered, 2=closed/delivered
			if ($object->status == Expedition::STATUS_VALIDATED && !getDolGlobalString('STOCK_CALCULATE_ON_SHIPMENT')) {
				if ($user->hasRight('expedition', 'creer')) {
					print dolGetButtonAction('', $langs->trans('SetToDraft'), 'default', $_SERVER["PHP_SELF"].'?action=setdraft&token='.newToken().'&id='.$object->id, '');
				}
			}
			if ($object->status == Expedition::STATUS_CLOSED) {
				if ($user->hasRight('expedition', 'creer')) {
					if ($user->admin) {
						print dolGetButtonAction('', $langs->trans('ReOpen'), 'default', $_SERVER["PHP_SELF"].'?action=reopen&token='.newToken().'&id='.$object->id, '');
					}
				}
			}

			// Send
			if (empty($user->socid)) {
				if ($object->status > 0) {
					if (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') || $user->hasRight('expedition', 'shipping_advance', 'send')) {
						print dolGetButtonAction('', $langs->trans('SendMail'), 'default', $_SERVER["PHP_SELF"].'?action=presend&token='.newToken().'&id='.$object->id.'&mode=init#formmailbeforetitle', '');
					} else {
						print dolGetButtonAction('', $langs->trans('SendMail'), 'default', $_SERVER['PHP_SELF']. '#', '', false);
					}
				}
			}

			// Create bill
			if (isModEnabled('invoice') && ($object->status == Expedition::STATUS_VALIDATED || $object->status == Expedition::STATUS_CLOSED)) {
if ($user->hasRight('facture', 'creer') && $user->admin) {
					if (getDolGlobalString('WORKFLOW_BILL_ON_SHIPMENT') !== '0') {
						print dolGetButtonAction('', $langs->trans('CreateBill'), 'default', DOL_URL_ROOT.'/compta/facture/card.php?action=create&origin='.$object->element.'&originid='.$object->id.'&socid='.$object->socid, '');
					}
				}
			}

			// This is just to generate a delivery receipt
			//var_dump($object->linkedObjectsIds['delivery']);
			if (getDolGlobalInt('MAIN_SUBMODULE_DELIVERY') && ($object->status == Expedition::STATUS_VALIDATED || $object->status == Expedition::STATUS_CLOSED) && $user->hasRight('expedition', 'delivery', 'creer') && empty($object->linkedObjectsIds['delivery'])) {
				print dolGetButtonAction('', $langs->trans('CreateDeliveryOrder'), 'default', $_SERVER["PHP_SELF"].'?action=create_delivery&token='.newToken().'&id='.$object->id, '');
			}

			// Set Billed and Closed
if ($user->hasRight('expedition', 'creer') && $object->status > 0) {
				if ($user->hasRight('expedition', 'creer') && $object->status > 0) {
					if (!$object->billed && getDolGlobalString('WORKFLOW_BILL_ON_SHIPMENT') !== '0') {
						if ($user->admin) {
							print dolGetButtonAction('', $langs->trans('ClassifyBilled'), 'default', $_SERVER["PHP_SELF"].'?action=classifybilled&token='.newToken().'&id='.$object->id, '');
						}
					}
					print dolGetButtonAction('', $langs->trans("Close"), 'default', $_SERVER["PHP_SELF"].'?action=classifyclosed&token='.newToken().'&id='.$object->id, '');
				}
			}

			// Cancel
			if ($object->status == Expedition::STATUS_VALIDATED) {
				if ($user->hasRight('expedition', 'creer')) {
					if ($user->admin) {
						print dolGetButtonAction('', $langs->trans('Cancel'), 'danger', $_SERVER["PHP_SELF"].'?action=cancel&token='.newToken().'&id='.$object->id.'&mode=init#formmailbeforetitle', '');
					}
				}
			}

			// Delete
			if ($user->hasRight('expedition', 'supprimer')) {
				print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER["PHP_SELF"].'?action=delete&token='.newToken().'&id='.$object->id, '');
			}
		}

		print '</div>';
	}


	/*
	 * Documents generated
	 */

	if ($action != 'presend' && $action != 'editline') {
		print '<div class="fichecenter"><div class="fichehalfleft">';

		$objectref = dol_sanitizeFileName($object->ref);
		$filedir = $conf->expedition->dir_output."/sending/".$objectref;

		$urlsource = $_SERVER["PHP_SELF"]."?id=".$object->id;

		$genallowed = $user->hasRight('expedition', 'lire');
		$delallowed = $user->hasRight('expedition', 'creer');

		print $formfile->showdocuments('expedition', $objectref, $filedir, $urlsource, $genallowed, $delallowed, $object->model_pdf, 1, 0, 0, 28, 0, '', '', '', $soc->default_lang);


		// Show links to link elements
		$tmparray = $form->showLinkToObjectBlock($object, array(), array('shipping'), 1);
		$linktoelem = $tmparray['linktoelem'];
		$htmltoenteralink = $tmparray['htmltoenteralink'];
		print $htmltoenteralink;

		$somethingshown = $form->showLinkedObjectBlock($object, $linktoelem);

		// Show online signature link
		$useonlinesignature = getDolGlobalInt('EXPEDITION_ALLOW_ONLINESIGN');

		if ($object->statut != Expedition::STATUS_DRAFT && $useonlinesignature) {
			print '<br><!-- Link to sign -->';
			require_once DOL_DOCUMENT_ROOT.'/core/lib/signature.lib.php';
			print showOnlineSignatureUrl('expedition', $object->ref, $object).'<br>';
		}

		print '</div><div class="fichehalfright">';

		// List of actions on element
		include_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
		$formactions = new FormActions($db);
		$somethingshown = $formactions->showactions($object, 'shipping', $socid, 1);

		print '</div></div>';
	}


	/*
	 * Action presend
	 */

	//Select mail models is same action as presend
	if (GETPOST('modelselected')) {
		$action = 'presend';
	}

	// Presend form
	$modelmail = 'shipping_send';
	$defaulttopic = 'SendShippingRef';
	$diroutput = $conf->expedition->dir_output.'/sending';
	$trackid = 'shi'.$object->id;

	include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

// End of page
// Your custom JS code to enable/disable MO serial inputs and handle quantities
print '<script type="text/javascript">
$(document).ready(function() {
    if (typeof window.getBaseSerialNumberPart !== "function") {
        window.getBaseSerialNumberPart = function(serial_string) {
            if (typeof serial_string !== "string") return "";
            let hyphen_count = 0;
            let cut_off_position = serial_string.length;
            for (let i = 0; i < serial_string.length; i++) {
                if (serial_string[i] === "-") {
                    hyphen_count++;
                    if (hyphen_count === 3) {
                        cut_off_position = i;
                        break;
                    }
                }
            }
            return serial_string.substring(0, cut_off_position);
        };
    }

    $("select.mo-product483-serial-select").each(function() {
        var $selectField = $(this);
        var baseMoRef = $selectField.data("basemoref");
        if (!baseMoRef) return;

        var hasAtLeastOneEnabledOption = false;

        $selectField.find("option").each(function() {
            var $option = $(this);
            var serialValue = $option.val();
            var serialText = $option.text();

            if (serialValue === "" || serialValue === "0" || serialValue === "-1") return;

            var actualSerial = "";
            var match = serialText.match(/^([^\s(]+)/);
            if (match && match[1]) {
                actualSerial = match[1];
            }

            if (actualSerial === "") return;

            var baseSerialPart = window.getBaseSerialNumberPart(actualSerial);

            if (baseSerialPart === baseMoRef) {
                if (actualSerial.length > baseSerialPart.length) {
                    var suffix_part = actualSerial.substring(baseSerialPart.length);
                    if (/^-([1-9][0-9]*)$/.test(suffix_part)) {
                        $option.prop("disabled", false);
                        hasAtLeastOneEnabledOption = true;
                    } else {
                        $option.prop("disabled", true);
                    }
                } else {
                    $option.prop("disabled", false);
                    hasAtLeastOneEnabledOption = true;
                }
            } else {
                $option.prop("disabled", true);
            }
        });
    });

    $("input.mo-serial-qty-input").each(function() {
        var $qtyInput = $(this);
        var fullSerial = $qtyInput.data("serial");
        var baseMoRef = $qtyInput.data("basemoref");

        if (typeof baseMoRef === "string" && baseMoRef !== "") {
            if (typeof fullSerial !== "string" || fullSerial === "") {
                $qtyInput.prop("disabled", true);
                $qtyInput.val("");
                return;
            }

            var baseSerialPart = window.getBaseSerialNumberPart(fullSerial);
            var isValid = false;

            if (baseSerialPart === baseMoRef) {
                if (fullSerial.length > baseSerialPart.length) {
                    var suffixPart = fullSerial.substring(baseSerialPart.length);
                    if (/^-([1-9][0-9]*)$/.test(suffixPart)) {
                        isValid = true;
                    }
                } else {
                    isValid = true;
                }
            }

            if (isValid) {
                $qtyInput.prop("disabled", false);
            } else {
                $qtyInput.prop("disabled", true);
                $qtyInput.val("");
            }

        } else {
            $qtyInput.prop("disabled", false);
        }
    });

    // Enforce readonly + zero on fully shipped lines
    $("input[name^=\'qtyasked\']").each(function() {
        var $qtyAskedInput = $(this);
        var nameAttr = $qtyAskedInput.attr("name");
        var indexMatch = nameAttr.match(/qtyasked(\\d+)/);

        if (indexMatch && indexMatch[1]) {
            var index = indexMatch[1];
            var qtyAsked = parseFloat($qtyAskedInput.val());
            var $qtyDeliveredInput = $("input[name=\'qtydelivered" + index + "\']");
            var qtyDelivered = 0;

            if ($qtyDeliveredInput.length) {
                qtyDelivered = parseFloat($qtyDeliveredInput.val());
            }

            if (!isNaN(qtyAsked) && !isNaN(qtyDelivered) && qtyAsked <= qtyDelivered) {
                var $simpleQtyInput = $("input[name=\'qtyl" + index + "\']");
                if ($simpleQtyInput.length) {
                    $simpleQtyInput.val(0).prop("readonly", true);
                }

                $("input[name^=\'qtyl" + index + "_\']").each(function() {
                    $(this).val(0).prop("readonly", true);
                });
            }
        }
    });
});
</script>';

llxFooter();
$db->close();


