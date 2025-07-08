<?php

// Load Dolibarr environment
require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/lib/mrp_mo.lib.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT.'/workstation/class/workstation.class.php';


/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("mrp", "stocks", "other", "product", "productbatch"));

// BEGIN Access Control for ST Group
// BEGIN Access Control for ST Group
// BEGIN Access Control for ST Group
if (empty($user->admin)) {
    $sql = "SELECT 1 FROM llx_usergroup_user WHERE fk_user = ".((int) $user->id)." AND fk_usergroup = 10";
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) == 0) {
        accessforbidden('You are not authorized to access this page. Group restriction.');
    }
}
// END Access Control for ST Group

// END Access Control for ST Group

// END Access Control for ST Group

// Get parameters
$id          = GETPOSTINT('id');
$ref         = GETPOST('ref', 'alpha');
$action      = GETPOST('action', 'aZ09');
$confirm     = GETPOST('confirm', 'alpha');
$cancel      = GETPOST('cancel', 'aZ09');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'mocard'; // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');
$lineid = GETPOSTINT('lineid');
$fk_movement = GETPOSTINT('fk_movement');
$fk_default_warehouse = GETPOSTINT('fk_default_warehouse');

$collapse = GETPOST('collapse', 'aZ09comma');

// Initialize a technical objects
$object = new Mo($db);
$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->mrp->dir_output.'/temp/massgeneration/'.$user->id;
$objectline = new MoLine($db);

$hookmanager->initHooks(array('moproduction', 'globalcard')); // Note that conf->hooks_modules contains array

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Initialize array of search criteria
$search_all = GETPOST("search_all", 'alpha');
$search = array();
foreach ($object->fields as $key => $val) {
	if (GETPOST('search_'.$key, 'alpha')) {
		$search[$key] = GETPOST('search_'.$key, 'alpha');
	}
}

if (empty($action) && empty($id) && empty($ref)) {
	$action = 'view';
}

// Load object
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be 'include', not 'include_once'.

// Security check - Protection if external user
//if ($user->socid > 0) accessforbidden();
//if ($user->socid > 0) $socid = $user->socid;
$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
$result = restrictedArea($user, 'mrp', $object->id, 'mrp_mo', '', 'fk_soc', 'rowid', $isdraft);

// Permissions
$permissionnote = $user->hasRight('mrp', 'write'); // Used by the include of actions_setnotes.inc.php
$permissiondellink = $user->hasRight('mrp', 'write'); // Used by the include of actions_dellink.inc.php
$permissiontoadd = $user->hasRight('mrp', 'write'); // Used by the include of actions_addupdatedelete.inc.php and actions_lineupdown.inc.php
$permissiontodelete = $user->hasRight('mrp', 'delete') || ($permissiontoadd && isset($object->status) && $object->status == $object::STATUS_DRAFT);

$permissiontoproduce = $permissiontoadd;
$permissiontoupdatecost = $user->hasRight('bom', 'read'); // User who can define cost must have knowledge of pricing

$upload_dir = $conf->mrp->multidir_output[isset($object->entity) ? $object->entity : 1];


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	$error = 0;

	$backurlforlist = DOL_URL_ROOT.'/mrp/mo_list.php';

	if (empty($backtopage) || ($cancel && empty($id))) {
		//var_dump($backurlforlist);exit;
		if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
			$backtopage = $backurlforlist;
		} else {
			$backtopage = DOL_URL_ROOT.'/mrp/mo_production.php?id='.($id > 0 ? $id : '__ID__');
		}
	}
	$triggermodname = 'MO_MODIFY'; // Name of trigger action code to execute when we modify record

	if ($action == 'confirm_cancel' && $confirm == 'yes' && !empty($permissiontoadd)) {
		$also_cancel_consumed_and_produced_lines = (GETPOST('alsoCancelConsumedAndProducedLines', 'alpha') ? 1 : 0);
		$result = $object->cancel($user, 0, $also_cancel_consumed_and_produced_lines);
		if ($result > 0) {
			header("Location: " . DOL_URL_ROOT.'/mrp/mo_card.php?id=' . $object->id);
			exit;
		} else {
			$action = '';
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action == 'confirm_cancel_no_stock_movement' && $confirm == 'yes' && !empty($permissiontoadd)) {
		// For this new cancel type, we explicitly do not cancel consumed/produced lines from stock.
		// The second parameter to $object->cancel() (also_cancel_consumed_and_produced_lines) is false by default if not passed,
		// but we pass true for the new third parameter $skip_stock_movement.
		$result = $object->cancel($user, 0, false, true);
		if ($result > 0) {
			header("Location: " . DOL_URL_ROOT.'/mrp/mo_card.php?id=' . $object->id);
			exit;
		} else {
			$action = ''; // Reset action to show errors on the current page
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action == 'confirm_delete' && $confirm == 'yes' && !empty($permissiontodelete)) {
		$also_cancel_consumed_and_produced_lines = (GETPOST('alsoCancelConsumedAndProducedLines', 'alpha') ? 1 : 0);
		$result = $object->delete($user, 0, $also_cancel_consumed_and_produced_lines);
		if ($result > 0) {
			header("Location: " . $backurlforlist);
			exit;
		} else {
			$action = '';
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action == 'confirm_return_materials' && $confirm == 'yes' && !empty($permissiontoadd)) {
		$db->begin();
		$error = 0;

		// Fetch original 'toconsume' lines to identify what *should* have been consumed.
		// Note: This logic assumes we are returning materials that were planned for consumption.
		// A more complex scenario would be to find actual 'consumed' lines if they were not deleted
		// by the cancel_no_stock_movement action (which they shouldn't be by design).
		// For now, we use the Mo->cancelConsumedAndProducedLines logic as a template,
		// but only for the 'consumed' part and ensuring it does 'reception' for them.

		// Re-fetch the object to ensure its lines are current, especially 'toconsume' lines.
		$object->fetch($id);
		$object->fetchLines(); // Ensure lines are loaded.

		$stockmove = new MouvementStock($db);
		$stockmove->setOrigin('mo_manual_return', $object->id); // Custom origin type for tracking

		// We need to identify what was actually consumed.
		// The easiest way is to look for 'consumed' lines that might still be there if a previous cancellation failed
		// or if this MO was in progress.
		// However, the most robust way for *this specific feature* (post STATUS_CANCELLED_NO_STOCK_MOVEMENT)
		// is to revert based on what *was* consumed.
		// The original $object->cancelConsumedAndProducedLines() is designed to do this.
		// We can call it here, but ensure it only affects consumed lines and deletes them.
		// The `cancelConsumedAndProducedLines` method in `mo.class.php` already handles
		// the stock reversal (reception for consumed items).
		// Parameters: $user, $mode (0=all, 1=consumed, 2=produced), $also_delete_lines
		// We want to reverse consumed items and delete the 'consumed' lines.
		$result_stock_return = $object->cancelConsumedAndProducedLines($user, 1, true, $notrigger);

		if ($result_stock_return < 0) {
			$error++;
			// Errors are already set by cancelConsumedAndProducedLines
		}

		if ($error) {
			$db->rollback();
			$action = 'return_materials'; // Stay on the confirmation step or go back to card with error
			// setEventMessages already called by cancelConsumedAndProducedLines
		} else {
			$db->commit();
			// Add a specific success message
			setEventMessages($langs->trans("MaterialsReturnedSuccessfully"), null, 'mesgs');

			// After successful return, change status to regular "Cancelled"
			$result_status_change = $object->setStatusCommon($user, Mo::STATUS_CANCELED, $notrigger, 'MRP_MO_CANCEL'); // Using existing cancel trigger
			if ($result_status_change < 0) {
				$error++; // Log or handle this error if needed, though unlikely after successful stock return
				setEventMessages($object->error, $object->errors, 'errors'); // Show error from setStatusCommon
			}

			if ($error) { // Check error again in case setStatusCommon failed
				$db->rollback(); // Rollback if status change failed
				$action = 'return_materials';
			} else {
				$db->commit();
				header("Location: " . DOL_URL_ROOT.'/mrp/mo_card.php?id=' . $object->id);
				exit;
			}
		}
	}


	// Actions cancel, add, update, delete or clone
	include DOL_DOCUMENT_ROOT.'/core/actions_addupdatedelete.inc.php';

	// Actions when linking object each other
	include DOL_DOCUMENT_ROOT.'/core/actions_dellink.inc.php';

	// Actions when printing a doc from card
	include DOL_DOCUMENT_ROOT.'/core/actions_printing.inc.php';

	// Actions to send emails
	$triggersendname = 'MO_SENTBYMAIL';
	$autocopy = 'MAIN_MAIL_AUTOCOPY_MO_TO';
	$trackid = 'mo'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';

	// Action to move up and down lines of object
	//include DOL_DOCUMENT_ROOT.'/core/actions_lineupdown.inc.php';	// Must be 'include', not 'include_once'

	if ($action == 'set_thirdparty' && $permissiontoadd) {
		$object->setValueFrom('fk_soc', GETPOSTINT('fk_soc'), '', null, 'date', '', $user, $triggermodname);
	}
	if ($action == 'classin' && $permissiontoadd) {
		$object->setProject(GETPOSTINT('projectid'));
	}

if ($action == 'confirm_reopen' && $user->admin) {
    $result = $object->setStatut($object::STATUS_INPROGRESS, 0, '', 'MRP_REOPEN');
}


	if (($action == 'confirm_addconsumeline' && GETPOST('addconsumelinebutton') && $permissiontoadd)
	|| ($action == 'confirm_addproduceline' && GETPOST('addproducelinebutton') && $permissiontoadd)) {
		$moline = new MoLine($db);

		// Line to produce
		$moline->fk_mo = $object->id;
		$moline->qty = GETPOSTFLOAT('qtytoadd');
		$moline->fk_product = GETPOSTINT('productidtoadd');
		if (GETPOST('addconsumelinebutton')) {
			$moline->role = 'toconsume';
		} else {
			$moline->role = 'toproduce';
		}
		$moline->origin_type = 'free'; // free consume line
		$moline->position = 0;

		// Is it a product or a service ?
		if (!empty($moline->fk_product)) {
			$tmpproduct = new Product($db);
			$tmpproduct->fetch($moline->fk_product);
			if ($tmpproduct->type == Product::TYPE_SERVICE) {
				$moline->fk_default_workstation = $tmpproduct->fk_default_workstation;
				$moline->disable_stock_change = 1;
				if ($tmpproduct->duration_unit) {
					$moline->qty = $tmpproduct->duration_value;
					include_once DOL_DOCUMENT_ROOT.'/core/class/cunits.class.php';
					$cunits = new CUnits($db);
					$res = $cunits->fetch(0, '', $tmpproduct->duration_unit, 'time');
					if ($res > 0) {
						$moline->fk_unit = $cunits->id;
					}
				}
			} else {
				$moline->disable_stock_change = 0;
				if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
					$moline->fk_unit = $tmpproduct->fk_unit;
				}
			}
		}
		// Extrafields
		$extralabelsline = $extrafields->fetch_name_optionals_label($object->table_element_line);
		$array_options = $extrafields->getOptionalsFromPost($object->table_element_line);
		// Unset extrafield
		if (is_array($extralabelsline)) {
			// Get extra fields
			foreach ($extralabelsline as $key => $value) {
				unset($_POST["options_".$key]);
			}
		}
		if (is_array($array_options) && count($array_options) > 0) {
			$moline->array_options = $array_options;
		}

		$resultline = $moline->create($user, false); // Never use triggers here
		if ($resultline <= 0) {
			$error++;
			setEventMessages($moline->error, $moline->errors, 'errors');
		}

		$action = '';
		// Redirect to refresh the tab information
		header("Location: ".$_SERVER["PHP_SELF"].'?id='.$object->id);
		exit;
	}

	if (in_array($action, array('confirm_consumeorproduce', 'confirm_consumeandproduceall')) && $permissiontoproduce) {
		$stockmove = new MouvementStock($db);

		$labelmovement = GETPOST('inventorylabel', 'alphanohtml');
		$codemovement  = GETPOST('inventorycode', 'alphanohtml');

		$db->begin();
		$pos = 0;
		// Process line to consume
		foreach ($object->lines as $line) {
			if ($line->role == 'toconsume') {
				$tmpproduct = new Product($db);
				$tmpproduct->fetch($line->fk_product);

				// Calculate already consumed for this specific line (re-adding this logic)
				$sub_lines_consumed = $object->fetchLinesLinked('consumed', $line->id);
				$alreadyconsumed_for_this_line = 0;
				foreach ($sub_lines_consumed as $sub_line) {
					$alreadyconsumed_for_this_line += $sub_line['qty'];
				}

				$i = 1;
				while (GETPOSTISSET('qty-'.$line->id.'-'.$i)) {
					$qtytoprocess = (float) price2num(GETPOST('qty-'.$line->id.'-'.$i));

					// Server-side validation for quantity to consume (re-adding this logic)
					if ($qtytoprocess > 0) {
						$max_allowed_to_consume_this_entry = $line->qty - $alreadyconsumed_for_this_line;
						if ($qtytoprocess > $max_allowed_to_consume_this_entry) {
							$langs->load("errors");
							$message = $langs->trans("ErrorQtyToConsumeExceedsRemainingPlanned", $tmpproduct->ref, $qtytoprocess, $max_allowed_to_consume_this_entry);
							if ($max_allowed_to_consume_this_entry < 0) {
								 $message = $langs->trans("ErrorQtyToConsumeMakesTotalNegative", $tmpproduct->ref, $qtytoprocess);
							} else if ($max_allowed_to_consume_this_entry == 0) {
								 $message = $langs->trans("ErrorQtyToConsumeNoneRemaining", $tmpproduct->ref, $qtytoprocess);
							}
							setEventMessages($message, null, 'errors');
							$error++; // Increment global error flag
						}
					}

					if ($qtytoprocess != 0) {
						// Check warehouse is set if we should have to
						if (GETPOSTISSET('idwarehouse-'.$line->id.'-'.$i)) {	// If there is a warehouse to set
							if (!(GETPOST('idwarehouse-'.$line->id.'-'.$i) > 0)) {	// If there is no warehouse set.
								$langs->load("errors");
								setEventMessages($langs->trans("ErrorFieldRequiredForProduct", $langs->transnoentitiesnoconv("Warehouse"), $tmpproduct->ref), null, 'errors');
								$error++;
							}
							if ($tmpproduct->status_batch && (!GETPOST('batch-'.$line->id.'-'.$i))) {
								$langs->load("errors");
								setEventMessages($langs->trans("ErrorFieldRequiredForProduct", $langs->transnoentitiesnoconv("Batch"), $tmpproduct->ref), null, 'errors');
								$error++;
							}
						}

						$idstockmove = 0;
						if (!$error && GETPOST('idwarehouse-'.$line->id.'-'.$i) > 0) {
							// Record stock movement
							$id_product_batch = 0;
							$stockmove->setOrigin($object->element, $object->id);
							$stockmove->context['mrp_role'] = 'toconsume';

								$batch_input_raw = GETPOST('batch-'.$line->id.'-'.$i);

								if ($tmpproduct->status_batch) { // Product requires serial numbers
									if ($qtytoprocess > 1) {
										// Multiple units of a serialized product
										$serial_numbers_raw = trim((string)$batch_input_raw);
										if (empty($serial_numbers_raw)) {
											$langs->load("errors");
											setEventMessages($langs->trans("ErrorSerialNumbersRequired", $tmpproduct->ref), null, 'errors');
											$error++;
										} else {
											$serial_numbers = array_map('trim', explode(',', $serial_numbers_raw));
											$unique_serial_numbers = array_unique($serial_numbers);

											if (count($serial_numbers) != $qtytoprocess) {
												$langs->load("errors");
												setEventMessages($langs->trans("ErrorIncorrectNumberOfSerialNumbers", count($serial_numbers), $qtytoprocess, $tmpproduct->ref), null, 'errors');
												$error++;
											} elseif (count($unique_serial_numbers) != count($serial_numbers)) {
												$langs->load("errors");
												setEventMessages($langs->trans("ErrorDuplicateSerialNumbersFound", $tmpproduct->ref), null, 'errors');
												$error++;
											} else {
												// All checks passed for number and uniqueness, now validate each serial
												foreach ($unique_serial_numbers as $current_serial) {
													if ($error) break; // Stop if an error occurred in a previous iteration

													// Check if serial exists for the product AND is in the specified warehouse with stock > 0
													$sql_check_serial_in_warehouse = "SELECT pb.qty";
													$sql_check_serial_in_warehouse .= " FROM ".MAIN_DB_PREFIX."product_lot as pl";
													$sql_check_serial_in_warehouse .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = pl.fk_product";
													$sql_check_serial_in_warehouse .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch as pb ON pb.fk_product_stock = ps.rowid";
													$sql_check_serial_in_warehouse .= " WHERE pl.fk_product = ".((int) $line->fk_product);
													$sql_check_serial_in_warehouse .= " AND pl.batch = '".$db->escape($current_serial)."'";
													$sql_check_serial_in_warehouse .= " AND ps.fk_entrepot = ".((int) GETPOST('idwarehouse-'.$line->id.'-'.$i));
													$sql_check_serial_in_warehouse .= " AND pb.batch = '".$db->escape($current_serial)."'";  // Ensure we are checking the same batch in product_batch table
													$sql_check_serial_in_warehouse .= " AND pb.qty > 0";
													$sql_check_serial_in_warehouse .= " AND pl.entity IN (".getEntity('productlot').")";

													$resql_check_serial_in_warehouse = $db->query($sql_check_serial_in_warehouse);
													if ($resql_check_serial_in_warehouse) {
														$obj_check_serial = $db->fetch_object($resql_check_serial_in_warehouse);
														if (!$obj_check_serial || $obj_check_serial->qty <= 0) { // Check if object exists and qty > 0
															$langs->load("errors");
															$selected_warehouse_obj = new Entrepot($db);
															$selected_warehouse_obj->fetch(GETPOST('idwarehouse-'.$line->id.'-'.$i));
															setEventMessages($langs->trans("ErrorSerialNumberNotInWarehouseOrNoStock", $current_serial, $tmpproduct->ref, $selected_warehouse_obj->label), null, 'errors');
															$error++;
														}
														// If num_rows > 0, serial exists in warehouse with stock, proceed
													} else {
														// SQL error
														dol_print_error($db);
														setEventMessages($db->lasterror(), null, 'errors');
														$error++;
													}

													if (!$error) {
														// Process stock movement for 1 unit with this serial
														$idstockmove_unit = 0;
														if ($qtytoprocess >= 0) { // Should always be positive for consumption here
															$idstockmove_unit = $stockmove->livraison($user, $line->fk_product, GETPOST('idwarehouse-'.$line->id.'-'.$i), 1, 0, $labelmovement, dol_now(), '', '', $current_serial, $id_product_batch, $codemovement);
														} else {
															// This case (negative qtytoprocess) should ideally be handled by prior validation or be for returns
															$idstockmove_unit = $stockmove->reception($user, $line->fk_product, GETPOST('idwarehouse-'.$line->id.'-'.$i), 1, 0, $labelmovement, dol_now(), '', '', $current_serial, $id_product_batch, $codemovement);
														}
														if ($idstockmove_unit < 0) {
															$error++;
															setEventMessages($stockmove->error, $stockmove->errors, 'errors');
															// No break here, let it try to record other serials if possible, or rely on db->rollback
														} else {
															// Record consumption for 1 unit
															$moline_unit = new MoLine($db);
															$moline_unit->fk_mo = $object->id;
															$moline_unit->position = $pos;
															$moline_unit->fk_product = $line->fk_product;
															$moline_unit->fk_warehouse = GETPOSTINT('idwarehouse-'.$line->id.'-'.$i);
															$moline_unit->qty = 1;
															$moline_unit->batch = $current_serial;
															$moline_unit->role = 'consumed';
															$moline_unit->fk_mrp_production = $line->id;
															$moline_unit->fk_stock_movement = $idstockmove_unit == 0 ? null : $idstockmove_unit;
															$moline_unit->fk_user_creat = $user->id;

															$resultmoline_unit = $moline_unit->create($user);
															if ($resultmoline_unit <= 0) {
																$error++;
																setEventMessages($moline_unit->error, $moline_unit->errors, 'errors');
															}
															$pos++;
														}
													}
												}
											}
										}
									} else { // Single unit of a serialized product ($qtytoprocess == 1)
										$batch_number_to_consume = trim((string)$batch_input_raw);
										if (empty($batch_number_to_consume)) {
										$langs->load("errors");
											setEventMessages($langs->trans("ErrorSerialNumberRequiredForProduct", $tmpproduct->ref), null, 'errors');
										$error++;
										} else {
											// Standard serial validation for single unit, now including warehouse check
											$sql_check_serial_in_warehouse = "SELECT pb.qty";
											$sql_check_serial_in_warehouse .= " FROM ".MAIN_DB_PREFIX."product_lot as pl";
											$sql_check_serial_in_warehouse .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = pl.fk_product";
											$sql_check_serial_in_warehouse .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch as pb ON pb.fk_product_stock = ps.rowid";
											$sql_check_serial_in_warehouse .= " WHERE pl.fk_product = ".((int) $line->fk_product);
											$sql_check_serial_in_warehouse .= " AND pl.batch = '".$db->escape($batch_number_to_consume)."'";
											$sql_check_serial_in_warehouse .= " AND ps.fk_entrepot = ".((int) GETPOST('idwarehouse-'.$line->id.'-'.$i));
											$sql_check_serial_in_warehouse .= " AND pb.batch = '".$db->escape($batch_number_to_consume)."'"; // Ensure we are checking the same batch in product_batch table
											$sql_check_serial_in_warehouse .= " AND pb.qty > 0";
											$sql_check_serial_in_warehouse .= " AND pl.entity IN (".getEntity('productlot').")";

											$resql_check_serial_in_warehouse = $db->query($sql_check_serial_in_warehouse);
											if ($resql_check_serial_in_warehouse) {
												$obj_check_serial = $db->fetch_object($resql_check_serial_in_warehouse);
												if (!$obj_check_serial || $obj_check_serial->qty <= 0) { // Check if object exists and qty > 0
													$langs->load("errors");
													$selected_warehouse_obj = new Entrepot($db);
													$selected_warehouse_obj->fetch(GETPOST('idwarehouse-'.$line->id.'-'.$i));
													setEventMessages($langs->trans("ErrorSerialNumberNotInWarehouseOrNoStock", $batch_number_to_consume, $tmpproduct->ref, $selected_warehouse_obj->label), null, 'errors');
													$error++;
												}
												// If num_rows > 0, serial exists in warehouse with stock, proceed
											} else {
												// SQL error
												dol_print_error($db);
												setEventMessages($db->lasterror(), null, 'errors');
												$error++;
											}

											if (!$error) {
												// Process stock movement for 1 unit
												if ($qtytoprocess >= 0) {
													$idstockmove = $stockmove->livraison($user, $line->fk_product, GETPOST('idwarehouse-'.$line->id.'-'.$i), 1, 0, $labelmovement, dol_now(), '', '', $batch_number_to_consume, $id_product_batch, $codemovement);
												} else {
													$idstockmove = $stockmove->reception($user, $line->fk_product, GETPOST('idwarehouse-'.$line->id.'-'.$i), 1, 0, $labelmovement, dol_now(), '', '', $batch_number_to_consume, $id_product_batch, $codemovement);
												}
												if ($idstockmove < 0) {
													$error++;
													setEventMessages($stockmove->error, $stockmove->errors, 'errors');
												} else {
													// Record consumption for 1 unit
													$moline = new MoLine($db);
													$moline->fk_mo = $object->id;
													$moline->position = $pos;
													$moline->fk_product = $line->fk_product;
													$moline->fk_warehouse = GETPOSTINT('idwarehouse-'.$line->id.'-'.$i);
													$moline->qty = 1; // qty is 1
													$moline->batch = $batch_number_to_consume;
													$moline->role = 'consumed';
													$moline->fk_mrp_production = $line->id;
													$moline->fk_stock_movement = $idstockmove == 0 ? null : $idstockmove;
													$moline->fk_user_creat = $user->id;

													$resultmoline = $moline->create($user);
													if ($resultmoline <= 0) {
														$error++;
														setEventMessages($moline->error, $moline->errors, 'errors');
													}
													$pos++;
												}
											}
									}
								}
								} else { // Product does not require serial numbers (original logic for non-serialized)
									// No batch number to validate, proceed directly to stock movement for the full quantity
								if ($qtytoprocess >= 0) {
										$idstockmove = $stockmove->livraison($user, $line->fk_product, GETPOST('idwarehouse-'.$line->id.'-'.$i), $qtytoprocess, 0, $labelmovement, dol_now(), '', '', '', $id_product_batch, $codemovement);
								} else {
										$idstockmove = $stockmove->reception($user, $line->fk_product, GETPOST('idwarehouse-'.$line->id.'-'.$i), $qtytoprocess * -1, 0, $labelmovement, dol_now(), '', '', '', $id_product_batch, $codemovement);
								}
								if ($idstockmove < 0) {
									$error++;
									setEventMessages($stockmove->error, $stockmove->errors, 'errors');
									} else {
										// Record consumption for full quantity
										$moline = new MoLine($db);
										$moline->fk_mo = $object->id;
										$moline->position = $pos;
										$moline->fk_product = $line->fk_product;
										$moline->fk_warehouse = GETPOSTINT('idwarehouse-'.$line->id.'-'.$i);
										$moline->qty = $qtytoprocess;
										// $moline->batch is not set as it's not a batch product
										$moline->role = 'consumed';
										$moline->fk_mrp_production = $line->id;
										$moline->fk_stock_movement = $idstockmove == 0 ? null : $idstockmove;
										$moline->fk_user_creat = $user->id;

										$resultmoline = $moline->create($user);
										if ($resultmoline <= 0) {
											$error++;
											setEventMessages($moline->error, $moline->errors, 'errors');
										}
										$pos++;
								}
							}
						}
							// No further processing for this $qtytoprocess entry if error occurred above for serialized items
							// For non-serialized, the original single $moline creation attempt happens inside the else block above.
					}

					$i++;
				}
			}
		}

		// Process line to produce
		$pos = 0;

		foreach ($object->lines as $line) {
			if ($line->role == 'toproduce') {
				$tmpproduct = new Product($db);
				$tmpproduct->fetch($line->fk_product);

				// Server-side category check for the current $line->fk_product
				$is_category_24_product_server = false;
				$sql_cat_server = "SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_categorie = 317 AND fk_product = ".((int) $line->fk_product);
				$resql_cat_server = $db->query($sql_cat_server);
				if ($resql_cat_server) {
					if ($db->num_rows($resql_cat_server) > 0) $is_category_24_product_server = true;
					$db->free($resql_cat_server);
				} else {
					dol_syslog("Error checking product category (server-side) for product ID ".$line->fk_product, LOG_ERR);
				}

				// Calculate already produced for this specific line (re-adding this logic)
				$sub_lines_produced = $object->fetchLinesLinked('produced', $line->id);
				$alreadyproduced_for_this_line = 0;
				foreach ($sub_lines_produced as $sub_line) {
					$alreadyproduced_for_this_line += $sub_line['qty'];
				}

				// Get the total quantity to produce for this line from the MO line itself.
				// This represents the full quantity for this "toproduce" line in the MO.
				$total_quantity_for_line = $line->qty;

				$i = 1; // Counter for form input fields (e.g., batchtoproduce-lineid-1, batchtoproduce-lineid-2)
				
				// Initialize a serial counter for product 31. This should persist across multiple form input fields for the same MO line.
				$serial_counter_for_product_31 = 1;

				while (GETPOSTISSET('qtytoproduce-'.$line->id.'-'.$i)) {
					$qtytoprocess = (float) price2num(GETPOST('qtytoproduce-'.$line->id.'-'.$i));
					$pricetoprocess = GETPOST('pricetoproduce-'.$line->id.'-'.$i) ? price2num(GETPOST('pricetoproduce-'.$line->id.'-'.$i)) : 0;

					// Server-side validation for quantity to produce (re-adding this logic)
					if ($qtytoprocess > 0) {
						$max_allowed_to_produce_this_entry = $line->qty - $alreadyproduced_for_this_line;
						if ($qtytoprocess > $max_allowed_to_produce_this_entry) {
							$langs->load("errors");
							$message = $langs->trans("ErrorQtyToProduceExceedsRemainingPlanned", $tmpproduct->ref, $qtytoprocess, $max_allowed_to_produce_this_entry);
							if ($max_allowed_to_produce_this_entry < 0) {
								 $message = $langs->trans("ErrorQtyToProduceMakesTotalNegative", $tmpproduct->ref, $qtytoprocess);
							} else if ($max_allowed_to_produce_this_entry == 0) {
								 $message = $langs->trans("ErrorQtyToProduceNoneRemaining", $tmpproduct->ref, $qtytoprocess);
							}
							setEventMessages($message, null, 'errors');
							$error++; // Increment global error flag
						}
					}

if ($pricetoprocess == 0) {
    $sql = "SELECT pmp FROM llx_product WHERE rowid = ".((int)$line->fk_product);
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj && $obj->pmp > 0) {
            $pricetoprocess = $obj->pmp;
        }
    }
}


					if ($qtytoprocess != 0) {
						// Check warehouse is set if we should have to
						if (GETPOSTISSET('idwarehousetoproduce-'.$line->id.'-'.$i)) {	// If there is a warehouse to set
							if (!(GETPOST('idwarehousetoproduce-'.$line->id.'-'.$i) > 0)) {	// If there is no warehouse set.
								$langs->load("errors");
								setEventMessages($langs->trans("ErrorFieldRequiredForProduct", $langs->transnoentitiesnoconv("Warehouse"), $tmpproduct->ref), null, 'errors');
								$error++;
							}
							// Batch check: Only error if batch is required AND (it's not product 31 OR (it is product 31 AND total_quantity_for_line is 1))
// Batch check: Only error if batch is required AND 
// 1) it's not product 31 (multi) AND not category 24 (multi), OR 
// 2) it's product 31 or category 24 but total_qty == 1
if (isModEnabled('productbatch') 
    && $tmpproduct->status_batch 
    && !GETPOST('batchtoproduce-'.$line->id.'-'.$i)
) {
    // skip batch-required check when this is a Cat-24 multi-qty produce
    $isMulti31 = ($line->fk_product == 483 && $total_quantity_for_line > 1);
    $isMultiCat24 = ($is_category_24_product_server && $total_quantity_for_line > 1);

    if (
        // if it's neither a multi-31 nor a multi-Cat24 (so truly batch-required)
        (! $isMulti31 && ! $isMultiCat24)
        // OR even if it is one of those, but qty==1 (single unit still needs batch)
        || $total_quantity_for_line == 1
    ) {
        $langs->load("errors");
        setEventMessages(
            $langs->trans(
                "ErrorFieldRequiredForProduct",
                $langs->transnoentitiesnoconv("Batch"),
                $tmpproduct->ref
            ),
            null,
            'errors'
        );
        $error++;
    }
}

						}

						$idstockmove = 0; // Initialize, will be set by stockmove->reception or loop
						if (!$error && GETPOST('idwarehousetoproduce-'.$line->id.'-'.$i) > 0) {
							$stockmove->origin_type = $object->element;
							$stockmove->origin_id = $object->id;
							$stockmove->context['mrp_role'] = 'toproduce';
							$id_product_batch = 0; // Assuming this is not used or handled differently for batch products

							// Consolidate product 31 (qty > 1) and category 24 (qty > 1)
							if (($line->fk_product == 483 && $total_quantity_for_line > 1) || ($is_category_24_product_server && $total_quantity_for_line > 1)) {
								if ($qtytoprocess > 0) { // Ensure there's a quantity for this specific form input
									// Serial generation logic is now common for both product 31 (qty>1) and Cat 24 (qty>1)
									// $serial_counter_for_product_31 was specific, now this logic is general for MO-Ref-X type serials.
									// The serial number is based on $alreadyproduced_for_this_line, which is specific to the MO line.
									for ($unit_count = 0; $unit_count < $qtytoprocess; $unit_count++) {
										$current_batch_to_use_unit = $object->ref . '-' . ($alreadyproduced_for_this_line + $unit_count + 1);


										// Calculate price per unit for this specific stock movement and MoLine
										// $pricetoprocess is the total price for $qtytoprocess items from the form input field
										$unit_price = ($qtytoprocess > 0 ? price2num($pricetoprocess / $qtytoprocess, 'MU') : price2num($pricetoprocess, 'MU'));

										$idstockmove_unit = $stockmove->reception($user, $line->fk_product, GETPOST('idwarehousetoproduce-'.$line->id.'-'.$i), 1, $unit_price, $labelmovement, '', '', $current_batch_to_use_unit, dol_now(), $id_product_batch, $codemovement);
										if ($idstockmove_unit < 0) {
											$error++;
											setEventMessages($stockmove->error, $stockmove->errors, 'errors');
											break; // Break from for loop
										}

										$moline_unit = new MoLine($db);
										$moline_unit->fk_mo = $object->id;
										$moline_unit->position = $pos;
										$moline_unit->fk_product = $line->fk_product;
										$moline_unit->fk_warehouse = GETPOSTINT('idwarehousetoproduce-'.$line->id.'-'.$i);
										$moline_unit->qty = 1; // Each MoLine is for a single unit
										$moline_unit->batch = $current_batch_to_use_unit;
										$moline_unit->role = 'produced';
										$moline_unit->fk_mrp_production = $line->id; // Link to the original "toproduce" line
										$moline_unit->fk_stock_movement = $idstockmove_unit;
										$moline_unit->fk_user_creat = $user->id;

										$resultmoline_unit = $moline_unit->create($user);
										if ($resultmoline_unit <= 0) {
											$error++;
											setEventMessages($moline_unit->error, $moline_unit->errors, 'errors');
											break; // Break from for loop
										}
										$pos++;
									}
									if ($error) break; // Break from while GETPOSTISSET loop if error occurred in for loop
								}
							} else { 
								// This else handles:
								// 1. Product 31 with total_quantity_for_line == 1
								// 2. Category 24 with total_quantity_for_line == 1
								// 3. All other products (not product 31, not category 24) regardless of their total_quantity_for_line.
								$current_batch_to_use = '';
								// For Cat 24 (qty==1) and Product 31 (qty==1), the batch is MO ref.
								// $total_quantity_for_line is defined earlier for the current $line.
								if ($is_category_24_product_server && $total_quantity_for_line == 1) {
									$current_batch_to_use = $object->ref;
								} elseif ($line->fk_product == 483 && $total_quantity_for_line == 1) {
									$current_batch_to_use = $object->ref;
								} else {
									// For all other products, or if batch is manually entered for Cat24/P31 Qty=1 (though UI should prevent this)
									$current_batch_to_use = GETPOST('batchtoproduce-'.$line->id.'-'.$i);
								}

								$idstockmove = $stockmove->reception($user, $line->fk_product, GETPOST('idwarehousetoproduce-'.$line->id.'-'.$i), $qtytoprocess, $pricetoprocess, $labelmovement, '', '', $current_batch_to_use, dol_now(), $id_product_batch, $codemovement);
								if ($idstockmove < 0) {
									$error++;
									setEventMessages($stockmove->error, $stockmove->errors, 'errors');
								}

								if (!$error) {
									// Standard MoLine creation
									$moline = new MoLine($db);
									$moline->fk_mo = $object->id;
									$moline->position = $pos;
									$moline->fk_product = $line->fk_product;
									$moline->fk_warehouse = GETPOSTINT('idwarehousetoproduce-'.$line->id.'-'.$i);
									$moline->qty = $qtytoprocess;
									$moline->batch = $current_batch_to_use; 
									$moline->role = 'produced';
									$moline->fk_mrp_production = $line->id;
									$moline->fk_stock_movement = $idstockmove;
									$moline->fk_user_creat = $user->id;
									$resultmoline = $moline->create($user);
									if ($resultmoline <= 0) {
										$error++;
										setEventMessages($moline->error, $moline_errors, 'errors');
									}
									$pos++;
								}
							}
						}
					}
					if ($error && ($line->fk_product == 483 && $total_quantity_for_line > 1 || $is_category_24_product_server && $total_quantity_for_line > 1) ) { // If error occurred in special multi-qty processing, break from while
						break; 
					}
					$i++;
				}
			}
		}
if (!$error) {
    // Recalculate $bomcostupdated based on consumed lines
    $bomcostupdated = 0;
    $consumedLines = $object->fetchLinesLinked('consumed', 0); // Fetch all consumed lines for this MO
    if (!empty($consumedLines)) {
        foreach ($consumedLines as $consumedLine) {
            $tmpproduct = new Product($db);
            $tmpproduct->fetch($consumedLine['fk_product']);
            $costprice = price2num(!empty($tmpproduct->cost_price) ? $tmpproduct->cost_price : $tmpproduct->pmp, 'MU');
            if (empty($costprice)) {
                require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
                $productFournisseur = new ProductFournisseur($db);
                if ($productFournisseur->find_min_price_product_fournisseur($consumedLine['fk_product'], $consumedLine['qty']) > 0) {
                    $costprice = $productFournisseur->fourn_unitprice;
                } else {
                    $costprice = 0;
                }
            }
            $bomcostupdated += price2num(($consumedLine['qty'] * $costprice) / $object->qty, 'MU');
        }
    }

    // Calculate $manufacturingcost based on produced lines
    $manufacturingcost = 0;
    $producedLines = $object->fetchLinesLinked('produced', 0); // Fetch all produced lines for this MO
    if (!empty($producedLines)) {
        foreach ($producedLines as $producedLine) {
            $tmpproduct = new Product($db);
            $tmpproduct->fetch($producedLine['fk_product']);
            $costprice = price2num(!empty($tmpproduct->cost_price) ? $tmpproduct->cost_price : $tmpproduct->pmp, 'MU');
            $manufacturingcost = price2num(($producedLine['qty'] * $costprice) / $object->qty, 'MU');
        }
    }

    // Update the manufacturing cost in the database
    $object->manufacturing_cost = $bomcostupdated; // Use $bomcostupdated as the primary cost
    if (empty($bomcostupdated)) { // Check $bomcostupdated directly
        $object->manufacturing_cost = $manufacturingcost; // Fallback to $manufacturingcost
    }
    $result = $object->update($user); // Save the updated cost to the database
    if ($result < 0) {
        $error++;
        dol_syslog("Failed to update MO with manufacturing_cost: " . $object->error, LOG_ERR);
        setEventMessages($object->error, $object->errors, 'errors');
    }

    $consumptioncomplete = true;
    $productioncomplete = true;

    if (GETPOSTINT('autoclose')) {
        foreach ($object->lines as $line) {
            if ($line->role == 'toconsume') {
                $arrayoflines = $object->fetchLinesLinked('consumed', $line->id);
                $alreadyconsumed = 0;
                foreach ($arrayoflines as $line2) {
                    $alreadyconsumed += $line2['qty'];
                }
                if ($alreadyconsumed < $line->qty) {
                    $consumptioncomplete = false;
                }
            }
            if ($line->role == 'toproduce') {
                $arrayoflines = $object->fetchLinesLinked('produced', $line->id);
                $alreadyproduced = 0;
                foreach ($arrayoflines as $line2) {
                    $alreadyproduced += $line2['qty'];
                }
                if ($alreadyproduced < $line->qty) {
                    $productioncomplete = false;
                }
            }
        }
    } else {
        $consumptioncomplete = false;
        $productioncomplete = false;
    }

    // Update status of MO
    dol_syslog("consumptioncomplete = " . json_encode($consumptioncomplete) . " productioncomplete = " . json_encode($productioncomplete));
    if ($consumptioncomplete && $productioncomplete) {
        $result = $object->setStatut($object::STATUS_PRODUCED, 0, '', 'MRP_MO_PRODUCED');
    } else {
        $result = $object->setStatut($object::STATUS_INPROGRESS, 0, '', 'MRP_MO_PRODUCED');
    }
    if ($result <= 0) {
        $error++;
        setEventMessages($object->error, $object->errors, 'errors');
    }
}

		if ($error) {
			$action = str_replace('confirm_', '', $action);
			$db->rollback();
		} else {
			$db->commit();

			// Redirect to avoid to action done a second time if we make a back from browser
			header("Location: ".$_SERVER["PHP_SELF"].'?id='.$object->id);
			exit;
		}
	}

	// Action close produced
	if ($action == 'confirm_produced' && $confirm == 'yes' && $permissiontoadd) {
		$result = $object->setStatut($object::STATUS_PRODUCED, 0, '', 'MRP_MO_PRODUCED');
		if ($result >= 0) {
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

				$object->generateDocument($model, $outputlangs, 0, 0, 0);
			}
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}

	if ($action == 'confirm_editline' && $permissiontoadd) {
		$moline = new MoLine($db);
		$res = $moline->fetch(GETPOSTINT('lineid'));
		if ($res > 0) { // $result was not defined, should be $res
			$extrafields->fetch_name_optionals_label($moline->element);
			foreach ($extrafields->attributes[$moline->table_element]['label'] as $key => $label) {
				$value = GETPOST('options_'.$key, 'alphanohtml');
				$moline->array_options["options_".$key] = $value;
			}
			$moline->qty = GETPOSTFLOAT('qty_lineProduce');
			if (GETPOSTISSET('warehouse_lineProduce')) {
				$moline->fk_warehouse = (GETPOSTINT('warehouse_lineProduce') > 0 ? GETPOSTINT('warehouse_lineProduce') : 0);
			}
			if (GETPOSTISSET('workstation_lineProduce')) {
				$moline->fk_default_workstation = (GETPOSTINT('workstation_lineProduce') > 0 ? GETPOSTINT('workstation_lineProduce') : 0);
			}

			$res = $moline->update($user);

			if ($res < 0) {
				setEventMessages($moline->error, $moline->errors, 'errors');
				// header("Location: ".$_SERVER["PHP_SELF"].'?id='.$object->id); // No exit here if error, show errors on current page
				// exit;
			} else { // Only redirect on success
				header("Location: ".$_SERVER["PHP_SELF"].'?id='.$object->id);
				exit;
			}
		}
	}
}



/*
 * View
 */

$form = new Form($db);
$formproject = new FormProjets($db);
$formproduct = new FormProduct($db);
$tmpwarehouse = new Entrepot($db);
$tmpbatch = new Productlot($db);
$tmpstockmovement = new MouvementStock($db);

$title = $langs->trans('CustomPCOrder'); // Changed from 'Mo'
$help_url = 'EN:Module_Manufacturing_Orders|FR:Module_Ordres_de_Fabrication|DE:Modul_Fertigungsauftrag';
$morejs = array('/mrp/js/lib_dispatch.js.php');
llxHeader('', $title, $help_url, '', 0, 0, $morejs, '', '', 'mod-mrp page-card_production');

$newToken = newToken();

// Part to show record
if ($object->id > 0 && (empty($action) || ($action != 'edit' && $action != 'create'))) {
	$res = $object->fetch_thirdparty();
	$res = $object->fetch_optionals();

	if (getDolGlobalString('STOCK_CONSUMPTION_FROM_MANUFACTURING_WAREHOUSE') && $object->fk_warehouse > 0) {
		$tmpwarehouse->fetch($object->fk_warehouse);
		$fk_default_warehouse = $object->fk_warehouse;
	}

	$head = moPrepareHead($object);

	print dol_get_fiche_head($head, 'production', $langs->trans("ManufacturingOrder"), -1, $object->picto);

	$formconfirm = '';

	// Confirmation to delete
	if ($action == 'delete') {
		$formquestion = array(
			array(
				'label' => $langs->trans('MoCancelConsumedAndProducedLines'),
				'name' => 'alsoCancelConsumedAndProducedLines',
				'type' => 'checkbox',
				'value' => 0
			),
		);
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('DeleteMo'), $langs->trans('ConfirmDeleteMo'), 'confirm_delete', $formquestion, 0, 1);
	}
	// Confirmation to delete line
	if ($action == 'deleteline') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&lineid='.$lineid.'&fk_movement='.$fk_movement, $langs->trans('DeleteLine'), $langs->trans('ConfirmDeleteLine'), 'confirm_deleteline', '', 0, 1);
	}
	// Clone confirmation
	if ($action == 'clone') {
		// Create an array for form
		$formquestion = array();
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('ToClone'), $langs->trans('ConfirmCloneMo', $object->ref), 'confirm_clone', $formquestion, 'yes', 1);
	}

	// Confirmation of validation
	if ($action == 'validate') {
		// We check that object has a temporary ref
		$ref_check = substr((string) $object->ref, 1, 4); // Cast to string to avoid issues if ref is null or not a string
		if ($ref_check == 'PROV') {
			$object->fetch_product();
			$numref = $object->getNextNumRef($object->product);
		} else {
			$numref = $object->ref;
		}

		$text = $langs->trans('ConfirmValidateMo', $numref);
		/*if (isModEnabled('notification'))
		 {
		 require_once DOL_DOCUMENT_ROOT . '/core/class/notify.class.php';
		 $notify = new Notify($db);
		 $text .= '<br>';
		 $text .= $notify->confirmMessage('BOM_VALIDATE', $object->socid, $object);
		 }*/

		$formquestion = array();
		if (isModEnabled('mrp')) {
			$langs->load("mrp");
			// require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php'; // Already required
			// $formproduct = new FormProduct($db); // Already instantiated
			$forcecombo = 0;
			if ($conf->browser->name == 'ie') {
				$forcecombo = 1; // There is a bug in IE10 that make combo inside popup crazy
			}
			$formquestion = array(
				// 'text' => $langs->trans("ConfirmClone"),
				// array('type' => 'checkbox', 'name' => 'clone_content', 'label' => $langs->trans("CloneMainAttributes"), 'value' => 1),
				// array('type' => 'checkbox', 'name' => 'update_prices', 'label' => $langs->trans("PuttingPricesUpToDate"), 'value' => 1),
			);
		}

		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('Validate'), $text, 'confirm_validate', $formquestion, 0, 1, 220);
	}

	// Confirmation to cancel
	if ($action == 'cancel') {
		$formquestion = array(
			array(
				'label' => $langs->trans('MoCancelConsumedAndProducedLines'),
				'name' => 'alsoCancelConsumedAndProducedLines',
				'type' => 'checkbox',
				'value' => !getDolGlobalString('MO_ALSO_CANCEL_CONSUMED_AND_PRODUCED_LINES_BY_DEFAULT') ? 0 : 1
			),
		);
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('CancelMo'), $langs->trans('ConfirmCancelMo'), 'confirm_cancel', $formquestion, 0, 1);
	}

	// Confirmation to cancel without stock movement
	if ($action == 'cancel_no_stock_movement') {
		// No specific questions needed for this type of cancel, but formconfirm expects $formquestion to be an array.
		$formquestion = array(
			// Optionally, add a hidden field or a specific message if needed.
			// For now, an empty array is fine as we don't ask for sub-line cancellation.
		);
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('CancelMoNoStockMovement'), $langs->trans('ConfirmCancelMoNoStockMovement'), 'confirm_cancel_no_stock_movement', $formquestion, 0, 1);
	}

	// Confirmation for returning materials
	if ($action == 'return_materials') {
		$formquestion = array(
			// No specific questions needed for this action, but formconfirm expects $formquestion to be an array.
		);
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('ReturnMaterialsToInventory'), $langs->trans('ConfirmReturnMaterialsToInventory'), 'confirm_return_materials', $formquestion, 0, 1);
	}

	// Call Hook formConfirm
	$parameters = array('formConfirm' => $formconfirm, 'lineid' => $lineid);
	$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	if (empty($reshook)) {
		$formconfirm .= $hookmanager->resPrint;
	} elseif ($reshook > 0) {
		$formconfirm = $hookmanager->resPrint;
	}

	// Print form confirm
	print $formconfirm;


	// MO file
	// ------------------------------------------------------------
	$linkback = '<a href="'.DOL_URL_ROOT.'/mrp/mo_list.php?restore_lastsearch_values=1'.(!empty($socid) ? '&socid='.$socid : '').'">'.$langs->trans("BackToList").'</a>';

	$morehtmlref = '<div class="refidno">';

	/*
	// Ref bis
	$morehtmlref.=$form->editfieldkey("RefBis", 'ref_client', $object->ref_client, $object, $user->rights->mrp->creer, 'string', '', 0, 1);
	$morehtmlref.=$form->editfieldval("RefBis", 'ref_client', $object->ref_client, $object, $user->rights->mrp->creer, 'string', '', null, null, '', 1);
	*/

	// Thirdparty
	if (is_object($object->thirdparty)) {
		$morehtmlref .= $object->thirdparty->getNomUrl(1, 'customer');
		if (!getDolGlobalString('MAIN_DISABLE_OTHER_LINK') && $object->thirdparty->id > 0) {
			$morehtmlref .= ' (<a href="'.DOL_URL_ROOT.'/commande/list.php?socid='.$object->thirdparty->id.'&search_societe='.urlencode($object->thirdparty->name).'">'.$langs->trans("OtherOrders").'</a>)';
		}
	}

	// Project
	if (isModEnabled('project')) {
		$langs->load("projects");
		if (is_object($object->thirdparty)) {
			$morehtmlref .= '<br>';
		}
		if ($permissiontoadd) {
			$morehtmlref .= img_picto($langs->trans("Project"), 'project', 'class="pictofixedwidth"');
			if ($action != 'classify') {
				$morehtmlref .= '<a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?action=classify&token='.newToken().'&id='.$object->id.'">'.img_edit($langs->transnoentitiesnoconv('SetProject')).'</a> ';
			}
			$morehtmlref .= $form->form_project($_SERVER['PHP_SELF'].'?id='.$object->id, $object->socid, $object->fk_project, ($action == 'classify' ? 'projectid' : 'none'), 0, 0, 0, 1, '', 'maxwidth300');
		} else {
			if (!empty($object->fk_project)) {
				$proj = new Project($db);
				$proj->fetch($object->fk_project);
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
	print '<table class="border centpercent tableforfield">'."\n";

	// Common attributes
	$keyforbreak = 'fk_warehouse';
	unset($object->fields['fk_project']);
	unset($object->fields['fk_soc']);
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_view.tpl.php';

	// Other attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_view.tpl.php';

	print '</table>';
	print '</div>';
	print '</div>';

	print '<div class="clearboth"></div>';

	print dol_get_fiche_end();


	if (!in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
		print '<div class="tabsAction">';

		$parameters = array();
		// Note that $action and $object may be modified by hook
		$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
		if (empty($reshook)) {
			// Validate
			if ($object->status == $object::STATUS_DRAFT) {
				if ($permissiontoadd) {
					if (empty($object->table_element_line) || (is_array($object->lines) && count($object->lines) > 0)) {
						print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=validate&token='.$newToken.'">'.$langs->trans("Validate").'</a>';
					} else {
						$langs->load("errors");
						print '<a class="butActionRefused" href="" title="'.$langs->trans("ErrorAddAtLeastOneLineFirst").'">'.$langs->trans("Validate").'</a>';
					}
				}
			}

			// Consume or produce
			if ($object->status == Mo::STATUS_VALIDATED || $object->status == Mo::STATUS_INPROGRESS) {
				if ($permissiontoproduce) {
					print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=consumeorproduce&token='.$newToken.'">'.$langs->trans('ConsumeOrProduce').'</a>';
				} else {
					print '<a class="butActionRefused classfortooltip" href="#" title="'.$langs->trans("NotEnoughPermissions").'">'.$langs->trans('ConsumeOrProduce').'</a>';
				}
			} elseif ($object->status == Mo::STATUS_DRAFT) {
				print '<a class="butActionRefused classfortooltip" href="#" title="'.$langs->trans("ValidateBefore").'">'.$langs->trans('ConsumeOrProduce').'</a>';
			}

			// ConsumeAndProduceAll
			if ($object->status == Mo::STATUS_VALIDATED || $object->status == Mo::STATUS_INPROGRESS) {
				if ($permissiontoproduce) {
					print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=consumeandproduceall&token='.$newToken.'">'.$langs->trans('ConsumeAndProduceAll').'</a>';
				} else {
					print '<a class="butActionRefused classfortooltip" href="#" title="'.$langs->trans("NotEnoughPermissions").'">'.$langs->trans('ConsumeAndProduceAll').'</a>';
				}
			} elseif ($object->status == Mo::STATUS_DRAFT) {
				print '<a class="butActionRefused classfortooltip" href="#" title="'.$langs->trans("ValidateBefore").'">'.$langs->trans('ConsumeAndProduceAll').'</a>';
			}

			// Cancel - Reopen
			if ($permissiontoadd) {
				if ($object->status == $object::STATUS_VALIDATED || $object->status == $object::STATUS_INPROGRESS) {
					$arrayproduced = $object->fetchLinesLinked('produced', 0);
					$nbProduced = 0;
					foreach ($arrayproduced as $lineproduced) {
						$nbProduced += $lineproduced['qty'];
					}
					if ($nbProduced > 0) {	// If production has started, we can close it
						print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=confirm_produced&confirm=yes&token='.$newToken.'">'.$langs->trans("Close").'</a>'."\n";
					} else {
						print '<a class="butActionRefused" href="#" title="'.$langs->trans("GoOnTabProductionToProduceFirst", $langs->transnoentitiesnoconv("Production")).'">'.$langs->trans("Close").'</a>'."\n";
					}

					if ($user->admin) {
						print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=cancel&token='.$newToken.'">'.$langs->trans("Cancel").'</a>'."\n";
					}

					// Conditional display for CancelNoStockMovement button
					$canShowCancelNoStockMovement = $user->admin;
					if (!$canShowCancelNoStockMovement) {
						$sql_group_check_cancel = "SELECT 1 FROM ".MAIN_DB_PREFIX."usergroup_user WHERE fk_user = ".((int) $user->id)." AND fk_usergroup = 10";
						$resql_group_check_cancel = $db->query($sql_group_check_cancel);
						if ($resql_group_check_cancel && $db->num_rows($resql_group_check_cancel) > 0) {
							$canShowCancelNoStockMovement = true;
						}
					}

					if ($canShowCancelNoStockMovement) {
						print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=cancel_no_stock_movement&token='.$newToken.'">'.$langs->trans("Cancel Technique").'</a>'."\n";
					}
				}

				if ($object->status == $object::STATUS_CANCELED || $object->status == $object::STATUS_CANCELLED_NO_STOCK_MOVEMENT) {
					print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=confirm_reopen&confirm=yes&token='.$newToken.'">'.$langs->trans("ReOpen").'</a>'."\n";
				}

			if ($object->status == $object::STATUS_PRODUCED) {
    if ($user->admin) {
        print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=confirm_reopen&token='.$newToken.'">'.$langs->trans('ReOpen').'</a>';
    } else {
        print '<a class="butActionRefused classfortooltip" href="#" title="'.$langs->trans("OnlyAdminCanReopen").'">'.$langs->trans('ReOpen').'</a>';
    }
}

			}

			// Button to manually return materials for STATUS_CANCELLED_NO_STOCK_MOVEMENT
			if ($object->status == Mo::STATUS_CANCELLED_NO_STOCK_MOVEMENT && $permissiontoadd) {
				// Check if there are 'toconsume' lines that had actual 'consumed' lines associated before cancellation,
				// or if there are still 'consumed' lines (which shouldn't be the case if cancel_no_stock_movement worked as intended for future uses).
				// For now, we'll assume if it's in this status, the button is relevant.
				// A more precise check would involve seeing if there *were* consumptions to reverse.
				// We also need to ensure this button is not shown if materials have already been returned by this manual action.
				// This might require an additional flag on the MO or checking if related stock movements (manual return type) exist.

				// Simple check: if any 'toconsume' lines exist, offer to return.
				// A better check: see if any 'consumed' lines *ever* existed for this MO. This requires deeper log/history checking or a flag.
				// Let's assume for now, if it's in this state, the user might want to click it.
				// We'll add a check later if stock movements for return already exist.
				$sqlCheckReturnMovements = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."stock_mouvement WHERE origintype = 'mo_manual_return' AND fk_origin = ".((int)$object->id);
				$resqlCheck = $db->query($sqlCheckReturnMovements);
				$manualReturnDone = 0;
				if ($resqlCheck) {
					$objCheck = $db->fetch_object($resqlCheck);
					if ($objCheck && $objCheck->nb > 0) {
						$manualReturnDone = 1;
					}
				}

				// Conditional display for ReturnMaterialsToInventory button
				$canShowReturnMaterials = $user->admin;
				if (!$canShowReturnMaterials) {
					$sql_group_check_return = "SELECT 1 FROM ".MAIN_DB_PREFIX."usergroup_user WHERE fk_user = ".((int) $user->id)." AND fk_usergroup = 7";
					$resql_group_check_return = $db->query($sql_group_check_return);
					if ($resql_group_check_return && $db->num_rows($resql_group_check_return) > 0) {
						$canShowReturnMaterials = true;
					}
				}

				if ($canShowReturnMaterials) {
					if (!$manualReturnDone) {
						print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=return_materials&token='.$newToken.'">'.$langs->trans("Revert Stock").'</a>';
					} else {
						print '<a class="butActionRefused classfortooltip" href="#" title="'.$langs->trans("MaterialsAlreadyReturned").'">'.$langs->trans("ReturnMaterialsToInventory").'</a>';
					}
				}
			}
		}

		print '</div>';
	}

	if (in_array($action, array('consumeorproduce', 'consumeandproduceall', 'addconsumeline', 'addproduceline', 'editline', 'return_materials'))) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="confirm_'.$action.'">';
		print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
		print '<input type="hidden" name="id" value="'.$id.'">';
		// Note: closing form is add end of page

		if (in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
			$defaultstockmovementlabel = GETPOST('inventorylabel', 'alphanohtml') ? GETPOST('inventorylabel', 'alphanohtml') : $langs->trans("ProductionForRef", $object->ref);
			$defaultstockmovementcode = GETPOST('inventorycode', 'alphanohtml') ? GETPOST('inventorycode', 'alphanohtml') : dol_print_date(dol_now(), 'dayhourlog');

			print '<div class="center'.(in_array($action, array('consumeorproduce', 'consumeandproduceall')) ? ' formconsumeproduce' : '').'">';
			print '<div class="opacitymedium hideonsmartphone paddingbottom">'.$langs->trans("ConfirmProductionDesc", $langs->transnoentitiesnoconv("Confirm")).'<br></div>';
			print '<span class="fieldrequired">'.$langs->trans("InventoryCode").':</span> <input type="text" class="minwidth150 maxwidth200" name="inventorycode" value="'.$defaultstockmovementcode.'"> Ã‚  ';
			print '<span class="clearbothonsmartphone"></span>';
			print $langs->trans("MovementLabel").': <input type="text" class="minwidth300" name="inventorylabel" value="'.$defaultstockmovementlabel.'"><br><br>';
			print '<input type="checkbox" id="autoclose" name="autoclose" value="1"'.(GETPOSTISSET('inventorylabel') ? (GETPOST('autoclose') ? ' checked="checked"' : '') : ' checked="checked"').'> <label for="autoclose">'.$langs->trans("AutoCloseMO").'</label><br>';
			print '<input type="submit" class="button" value="'.$langs->trans("Confirm").'" name="confirm">';
			print ' Ã‚  ';
			print '<input class="button button-cancel" type="submit" value="'.$langs->trans("Cancel").'" name="cancel">';
			print '<br><br>';
			print '</div>';

			print '<br>';
		}
	}


	/*
	 * Lines
	 */
	$collapse = 1;

	if (!empty($object->table_element_line)) {
		// Show object lines
		$object->fetchLines();

        // New: Identify products consumed with negative quantity
        $productsWithNegativeConsumptionQty = array();
        if (!empty($object->lines)) {
            foreach ($object->lines as $consumptionLineCheck) { // Use a distinct loop variable name
                if ($consumptionLineCheck->role == 'toconsume' && $consumptionLineCheck->qty < 0) {
                    $productsWithNegativeConsumptionQty[$consumptionLineCheck->fk_product] = true;
                }
            }
        }

		$bomcost = 0;
		if ($object->fk_bom > 0) {
			$bom = new BOM($db);
			$res = $bom->fetch($object->fk_bom);
			if ($res > 0) {
				$bom->calculateCosts();
				$bomcost = $bom->unit_cost;
			}
		}

		// Lines to consume

		print '<!-- Lines to consume -->'."\n";
		print '<div class="fichecenter">';
		print '<div class="fichehalfleft">';
		print '<div class="clearboth"></div>';

		$url = $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=addconsumeline&token='.newToken();
		$permissiontoaddaconsumeline = ($object->status != $object::STATUS_PRODUCED && $object->status != $object::STATUS_CANCELED) ? 1 : -2;
		$parameters = array('morecss' => 'reposition');
		$helpText = '';
		if ($permissiontoaddaconsumeline == -2) {
			$helpText = $langs->trans('MOIsClosed');
		}

		$newcardbutton = '';
		if ($action != 'consumeorproduce' && $action != 'consumeandproduceall') {
			$newcardbutton = dolGetButtonTitle($langs->trans('AddNewConsumeLines'), $helpText, 'fa fa-plus-circle size15x', $url, '', $permissiontoaddaconsumeline, $parameters);
		}

		print load_fiche_titre($langs->trans('Consumption'), $newcardbutton, '', 0, '', '', '');

		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder noshadow centpercent nobottom">';

		print '<!-- Line of title for products to consume -->'."\n";
		print '<tr class="liste_titre trheight5em">';
		// Product
		print '<td>'.$langs->trans("Product").'</td>';
		// Qty
		print '<td class="right">'.$langs->trans("Qty").'</td>';
		// Unit
		print '<td></td>';
		// Cost price
		if ($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION')) {
			print '<td class="right">'.$langs->trans("UnitCost").'</td>';
		}
		// Qty already consumed
		print '<td class="right classfortooltip" title="'.$langs->trans("QtyAlreadyConsumed").'">';
		print $langs->trans("QtyAlreadyConsumedShort");
		print '</td>';
		// Warehouse
		print '<td>';
		if ($collapse || in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
			print $langs->trans("Warehouse");
			if (isModEnabled('workstation')) {
				print ' '.$langs->trans("or").' '.$langs->trans("Workstation");
			}
			// Select warehouse to force it everywhere
			if (in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
				$listwarehouses = $tmpwarehouse->list_array(1);
				if (count($listwarehouses) > 1) {
					print '<br>'.$form->selectarray('fk_default_warehouse', $listwarehouses, $fk_default_warehouse, $langs->trans("ForceTo"), 0, 0, '', 0, 0, 0, '', 'minwidth100 maxwidth200', 1);
				} elseif (count($listwarehouses) == 1) {
					print '<br>'.$form->selectarray('fk_default_warehouse', $listwarehouses, $fk_default_warehouse, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100 maxwidth200', 1);
				}
			}
		}
		print '</td>';

		if (isModEnabled('stock')) {
			// Available
			print '<td align="right">';
			if ($collapse || in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
				print $langs->trans("Stock");
			}
			print '</td>';
		}
		// Lot - serial
		if (isModEnabled('productbatch')) {
			print '<td>';
			if ($collapse || in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
				print $langs->trans("Batch");
			}
			print '</td>';
		}

		// Split
		print '<td></td>';

		// SplitAll
		print '<td></td>';

		// Edit Line
		if ($object->status == Mo::STATUS_DRAFT) {
			print '<td></td>';
		}

		// Action
		if ($permissiontodelete) {
			print '<td></td>';
		}

		print '</tr>';

		if ($action == 'addconsumeline') {
			print '<!-- Add line to consume -->'."\n";
			print '<tr class="liste_titre">';
			// Product
			print '<td>';
			print $form->select_produits(0, 'productidtoadd', '', 0, 0, -1, 2, '', 1, array(), 0, '1', 0, 'maxwidth150');
			print '</td>';
			// Qty
			print '<td class="right"><input type="text" name="qtytoadd" value="1" class="width40 right"></td>';
			// Unit
			print '<td>';
			//if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
			//...
			//}
			print '</td>';
			// Cost price
			if ($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION')) {
				print '<td></td>';
			}

			$colspan = 3;
			if (isModEnabled('stock')) {
				$colspan++;
			}
			if (isModEnabled('productbatch')) {
				$colspan++;
			}
			// Qty already consumed + Warehouse
			print '<td colspan="'.$colspan.'">';
			print '<input type="submit" class="button buttongen button-add" name="addconsumelinebutton" value="'.$langs->trans("Add").'">';
			print '<input type="submit" class="button buttongen button-cancel" name="canceladdconsumelinebutton" value="'.$langs->trans("Cancel").'">';
			print '</td>';
			// Split All
			print '<td></td>';
			// Edit Line
			if ($object->status == Mo::STATUS_DRAFT) {
				print '<td></td>';
			}
			// Action
			if ($permissiontodelete) {
				print '<td></td>';
			}
			print '</tr>';

			// Extrafields Line
			if (is_object($objectline)) {
				$extrafields->fetch_name_optionals_label($object->table_element_line);
				$temps = $objectline->showOptionals($extrafields, 'edit', array(), '', '', 1, 'line');
				if (!empty($temps)) {
					print '<tr class="liste_titre"><td style="padding-top: 20px" colspan="9" id="extrafield_lines_area_edit" name="extrafield_lines_area_edit">';
					print $temps;
					print '</td></tr>';
				}
			}
		}

		// Lines to consume

		$bomcostupdated = 0;	// We will recalculate the unitary cost to produce a product using the real "products to consume into MO"

		if (!empty($object->lines)) {
			$nblinetoconsume = 0;
			foreach ($object->lines as $line) {
				if ($line->role == 'toconsume') {
					$nblinetoconsume++;
				}
			}

			$nblinetoconsumecursor = 0;
			foreach ($object->lines as $line) {
				if ($line->role == 'toconsume') {
					$nblinetoconsumecursor++;

					$tmpproduct = new Product($db);
					$tmpproduct->fetch($line->fk_product);
					$linecost = price2num($tmpproduct->pmp, 'MT');

					if ($object->qty > 0) {
						// add free consume line cost to $bomcostupdated
						$costprice = price2num((!empty($tmpproduct->cost_price)) ? $tmpproduct->cost_price : $tmpproduct->pmp);
						if (empty($costprice)) {
							require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
							$productFournisseur = new ProductFournisseur($db);
							if ($productFournisseur->find_min_price_product_fournisseur($line->fk_product, $line->qty) > 0) {
								$costprice = $productFournisseur->fourn_unitprice;
							} else {
								$costprice = 0;
							}
						}

						$useunit = (($tmpproduct->type == Product::TYPE_PRODUCT && getDolGlobalInt('PRODUCT_USE_UNITS')) || (($tmpproduct->type == Product::TYPE_SERVICE) && ($line->fk_unit)));

						if ($useunit && $line->fk_unit > 0) {
							$reg = [];
							$qtyhourservice = 0;
							if (preg_match('/^(\d+)([a-z]+)$/', (string) $tmpproduct->duration, $reg)) {
								$qtyhourservice = convertDurationtoHour((float) $reg[1], (string) $reg[2]);
							}
							$qtyhourforline = 0;
							if ($line->fk_unit) {
								$unitforline = measuringUnitString($line->fk_unit, '', '', 1);
								$qtyhourforline = convertDurationtoHour($line->qty, $unitforline);
							}

							if ($qtyhourservice && $qtyhourforline) {
								$linecost = price2num(($qtyhourforline / $qtyhourservice * $costprice) / $object->qty, 'MT');	// price for line for all quantities
								$bomcostupdated += price2num(($qtyhourforline / $qtyhourservice * $costprice) / $object->qty, 'MU');	// same but with full accuracy
							} else {
								$linecost = price2num(($line->qty * $costprice) / $object->qty, 'MT');	// price for line for all quantities
								$bomcostupdated += price2num(($line->qty * $costprice) / $object->qty, 'MU');	// same but with full accuracy
							}
						} else {
							$linecost = price2num(($line->qty * $costprice) / $object->qty, 'MT');	// price for line for all quantities
							$bomcostupdated += price2num(($line->qty * $costprice) / $object->qty, 'MU');	// same but with full accuracy
						}
					}

					$bomcostupdated = price2num($bomcostupdated, 'MU');
					$arrayoflines = $object->fetchLinesLinked('consumed', $line->id);
					$alreadyconsumed = 0;
					foreach ($arrayoflines as $line2) {
						$alreadyconsumed += $line2['qty'];
					}

					if ($action == 'editline' && $lineid == $line->id) {
						$linecost = price2num($tmpproduct->pmp, 'MT');

						$arrayoflines = $object->fetchLinesLinked('consumed', $line->id);
						$alreadyconsumed = 0;
						if (is_array($arrayoflines) && !empty($arrayoflines)) {
							foreach ($arrayoflines as $line2) {
								$alreadyconsumed += $line2['qty'];
							}
						}
						$suffix = '_' . $line->id;
						print '<!-- Line to dispatch ' . $suffix . ' (line edited) -->' . "\n";
						// hidden fields for js function
						print '<input id="qty_ordered' . $suffix . '" type="hidden" value="' . $line->qty . '">';
						// Duration - Time spent
						print '<input id="qty_dispatched' . $suffix . '" type="hidden" value="' . $alreadyconsumed . '">';
						print '<tr>';
						print '<input name="lineid" type="hidden" value="' . $line->id . '">';

						// Product
						print '<td>' . $tmpproduct->getNomUrl(1);
						print '<br><div class="opacitymedium small tdoverflowmax150" title="' . dol_escape_htmltag($tmpproduct->label) . '">' . $tmpproduct->label . '</span>';
						print '</td>';

						// Qty
						print '<td class="right nowraponall">';
						print '<input class="width40 right" name="qty_lineProduce" value="'. $line->qty.'">';
						print '</td>';

						// Unit
						print '<td class="right nowraponall">';
						$useunit = (($tmpproduct->type == Product::TYPE_PRODUCT && getDolGlobalInt('PRODUCT_USE_UNITS')) || (($tmpproduct->type == Product::TYPE_SERVICE) && ($line->fk_unit)));
						if ($useunit) {
							print measuringUnitString($line->fk_unit, '', '', 2);
						}
						print '</td>';

						// Cost price
						if ($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION')) {
							print '<td></td>';
						}

						// Qty consumed
						print '<td class="right">';
						print ' ' . price2num($alreadyconsumed, 'MS');
						print '</td>';

						// Warehouse / Workstation
						print '<td>';
						if ($tmpproduct->type == Product::TYPE_PRODUCT) {
							print $formproduct->selectWarehouses($line->fk_warehouse, 'warehouse_lineProduce', 'warehouseopen', 1);
						} elseif (isModEnabled('workstation')) {
							print $formproduct->selectWorkstations($line->fk_default_workstation, 'workstation_lineProduce', 1);
						}
						print '</td>';

						// Stock
						if (isModEnabled('stock')) {
							print '<td class="nowraponall right">';
							if ($tmpproduct->isStockManaged()) {
								if ($tmpproduct->stock_reel < ($line->qty - $alreadyconsumed)) {
									print img_warning($langs->trans('StockTooLow')).' ';
								}
								print '<span class="left">'. $tmpproduct->stock_reel  .' </span>';
							}
							print '</td>';
						}

						// Lot - serial
						if (isModEnabled('productbatch')) {
							print '<td></td>';
						}
						// Split + SplitAll + Edit line + Delete
						print '<td colspan="'.(3 + ($object->status == Mo::STATUS_DRAFT ? 1 : 0) + ($permissiontodelete ? 1 : 0)).'">';
						print '<input type="submit" class="button buttongen button-add small nominwidth" name="save" value="' . $langs->trans("Save") . '">';
						print '<input type="submit" class="button buttongen button-cancel small nominwidth" name="cancel" value="' . $langs->trans("Cancel") . '">';
						print '</td>';

						print '</tr>';

						// Extrafields Line
						if (!empty($extrafields)) {
							$line->fetch_optionals();
							$temps = $line->showOptionals($extrafields, 'edit', array(), '', '', 1, 'line');
							if (!empty($temps)) {
								$colspan = 10;
								print '<tr><td colspan="'.$colspan.'"><div style="padding-top: 20px" id="extrafield_lines_area_edit" name="extrafield_lines_area_edit">';
								print $temps;
								print '</div></td></tr>';
							}
						}
					} else {
						$suffix = '_' . $line->id;
						print '<!-- Line to dispatch ' . $suffix . ' -->' . "\n";
						// hidden fields for js function
						print '<input id="qty_ordered' . $suffix . '" type="hidden" value="' . $line->qty . '">';
						print '<input id="qty_dispatched' . $suffix . '" type="hidden" value="' . $alreadyconsumed . '">';

						print '<tr data-line-id="' . $line->id . '">';

						// Product
						print '<td>' . $tmpproduct->getNomUrl(1);
						print '<br><div class="opacitymedium small tdoverflowmax150" title="' . dol_escape_htmltag($tmpproduct->label) . '">' . $tmpproduct->label . '</div>';
						print '</td>';

						// Qty
						print '<td class="right nowraponall">';
						$help = '';
						if ($line->qty_frozen) {
							$help = ($help ? '<br>' : '') . '<strong>' . $langs->trans("QuantityFrozen") . '</strong>: ' . yn(1) . ' (' . $langs->trans("QuantityConsumedInvariable") . ')';
							print $form->textwithpicto('', $help, -1, 'lock') . ' ';
						}
						if ($line->disable_stock_change) {
							$help = ($help ? '<br>' : '') . '<strong>' . $langs->trans("DisableStockChange") . '</strong>: ' . yn(1) . ' (' . (($tmpproduct->type == Product::TYPE_SERVICE && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) ? $langs->trans("NoStockChangeOnServices") : $langs->trans("DisableStockChangeHelp")) . ')';
							print $form->textwithpicto('', $help, -1, 'help') . ' ';
						}
						print price2num($line->qty, 'MS');
						print '</td>';

						// Unit
						print '<td class="right nowraponall">';
						$useunit = (($tmpproduct->type == Product::TYPE_PRODUCT && getDolGlobalInt('PRODUCT_USE_UNITS')) || (($tmpproduct->type == Product::TYPE_SERVICE) && ($line->fk_unit)));
						if ($useunit) {
							print measuringUnitString($line->fk_unit, '', '', 2);
						}
						print '</td>';

						// Cost price
						if ($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION')) {
							print '<td class="right nowraponall">';
							print price($linecost);
							print '</td>';
						}

						// Already consumed
						print '<td class="right">';
						if ($alreadyconsumed) {
							print '<script>';
							print 'jQuery(document).ready(function() {
								jQuery("#expandtoproduce' . $line->id . '").click(function() {
									console.log("Expand mrp_production line ' . $line->id . '");
									jQuery(".expanddetail' . $line->id . '").toggle();';
							if ($nblinetoconsume == $nblinetoconsumecursor) {    // If it is the last line
								print 'if (jQuery("#tablelines").hasClass("nobottom")) { jQuery("#tablelines").removeClass("nobottom"); } else { jQuery("#tablelines").addClass("nobottom"); }';
							}
							print '
								});
							});';
							print '</script>';
							if (empty($conf->use_javascript_ajax)) {
								print '<a href="' . $_SERVER["PHP_SELF"] . '?collapse=' . $collapse . ',' . $line->id . '">';
							}
							print img_picto($langs->trans("ShowDetails"), "chevron-down", 'id="expandtoproduce' . $line->id . '"');
							if (empty($conf->use_javascript_ajax)) {
								print '</a>';
							}
						} else {
							if ($nblinetoconsume == $nblinetoconsumecursor) {    // If it is the last line
								print '<script>jQuery("#tablelines").removeClass("nobottom");</script>';
							}
						}
						print ' ' . price2num($alreadyconsumed, 'MS');
						print '</td>';

						// Warehouse and/or workstation
						print '<td class="tdoverflowmax100">';
						if ($tmpproduct->isStockManaged()) {
							// When STOCK_CONSUMPTION_FROM_MANUFACTURING_WAREHOUSE is set, we always use the warehouse of the MO, the same than production.
							if (getDolGlobalString('STOCK_CONSUMPTION_FROM_MANUFACTURING_WAREHOUSE') && $tmpwarehouse->id > 0) {
								print img_picto('', $tmpwarehouse->picto) . " " . $tmpwarehouse->label;
							} else {
								if ($line->fk_warehouse > 0) {
									$warehouseline = new Entrepot($db);
									$warehouseline->fetch($line->fk_warehouse);
									print $warehouseline->getNomUrl(1);
								}
							}
						}
						if (isModEnabled('workstation') && $line->fk_default_workstation > 0) {
							$tmpworkstation = new Workstation($db);
							$tmpworkstation->fetch($line->fk_default_workstation);
							print $tmpworkstation->getNomUrl(1);
						}
						print '</td>';

						// Stock
						if (isModEnabled('stock')) {
							print '<td class="nowraponall right">';
							if (!getDolGlobalString('STOCK_SUPPORTS_SERVICES') && $tmpproduct->type != Product::TYPE_SERVICE) {
								if (!$line->disable_stock_change && $tmpproduct->stock_reel < ($line->qty - $alreadyconsumed)) {
									print img_warning($langs->trans('StockTooLow')) . ' ';
								}
								if (!getDolGlobalString('STOCK_CONSUMPTION_FROM_MANUFACTURING_WAREHOUSE') || empty($tmpwarehouse->id)) {
									print price2num($tmpproduct->stock_reel, 'MS'); // Available
								} else {
									// Print only the stock in the selected warehouse
									$tmpproduct->load_stock();
									$wh_stock = isset($tmpproduct->stock_warehouse[$tmpwarehouse->id]) ? $tmpproduct->stock_warehouse[$tmpwarehouse->id] : null;
									if (!empty($wh_stock)) {
										print price2num($wh_stock->real, 'MS');
									} else {
										print "0";
									}
								}
							}
							print '</td>';
						}

						// Lot
						if (isModEnabled('productbatch')) {
							print '<td></td>';
						}

						// Split
						print '<td></td>';

						// Split All
						print '<td></td>';

						// Action Edit line
						if ($object->status == Mo::STATUS_DRAFT) {
							$href = $_SERVER["PHP_SELF"] . '?id=' . ((int) $object->id) . '&action=editline&token=' . newToken() . '&lineid=' . ((int) $line->id);
							print '<td class="center">';
							print '<a class="reposition editfielda" href="' . $href . '">';
							print img_picto($langs->trans('TooltipEditAndRevertStockMovement'), 'edit');
							print '</a>';
							print '</td>';
						}

						// Action delete line
						if ($permissiontodelete) {
							$href = $_SERVER["PHP_SELF"] . '?id=' . ((int) $object->id) . '&action=deleteline&token=' . newToken() . '&lineid=' . ((int) $line->id);
							print '<td class="center">';
							print '<a class="reposition" href="' . $href . '">';
							print img_picto($langs->trans('TooltipDeleteAndRevertStockMovement'), 'delete');
							print '</a>';
							print '</td>';
						}

						print '</tr>';

						// Extrafields Line
						if (!empty($extrafields)) {
							$line->fetch_optionals();
							$temps = $line->showOptionals($extrafields, 'view', array(), '', '', 1, 'line');
							if (!empty($temps)) {
								$colspan = 10;
                                if (!($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION'))) $colspan--;
                                if (!isModEnabled('stock')) $colspan--;
                                if (!isModEnabled('productbatch')) $colspan--;
                                if ($object->status != Mo::STATUS_DRAFT) $colspan--;
                                if (!$permissiontodelete) $colspan--;

								print '<tr><td colspan="'.$colspan.'"><div id="extrafield_lines_area_'.$line->id.'" name="extrafield_lines_area_'.$line->id.'">';
								print $temps;
								print '</div></td></tr>';
							}
						}
					}

					// Show detailed of already consumed with js code to collapse
					foreach ($arrayoflines as $line2) {
						print '<tr class="expanddetail'.$line->id.' hideobject opacitylow">';

						// Date
						print '<td>';
						$tmpstockmovement->id = $line2['fk_stock_movement'];
						print '<a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?search_ref='.$tmpstockmovement->id.'">'.img_picto($langs->trans("StockMovement"), 'movement', 'class="paddingright"').'</a>';
						print dol_print_date($line2['date'], 'dayhour', 'tzuserrel');
						print '</td>';

						// Qty
						print '<td></td>';

						// Unit
						print '<td></td>';

						// Cost price
						if ($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION')) {
							print '<td></td>';
						}

						//Already consumed
						print '<td class="right">'.$line2['qty'].'</td>';

						// Warehouse
						print '<td class="tdoverflowmax150">';
						if ($line2['fk_warehouse'] > 0) {
							$result = $tmpwarehouse->fetch($line2['fk_warehouse']);
							if ($result > 0) {
								print $tmpwarehouse->getNomUrl(1);
							}
						}
						print '</td>';

						// Stock
						if (isModEnabled('stock')) {
							print '<td></td>';
						}

						// Lot Batch
						if (isModEnabled('productbatch')) {
							print '<td>';
							if ($line2['batch'] != '') {
								$tmpbatch->fetch(0, $line2['fk_product'], $line2['batch']);
								print $tmpbatch->getNomUrl(1);
							}
							print '</td>';
						}

						// Split
						print '<td></td>';

						// Split All
						print '<td></td>';

						// Action Edit line
						if ($object->status == Mo::STATUS_DRAFT) {
							$href = $_SERVER["PHP_SELF"] . '?id=' . ((int) $object->id) . '&action=editline&token=' . newToken() . '&lineid=' . ((int) $line2['rowid']);
							print '<td class="center">';
							print '<a class="reposition" href="' . $href . '">';
							print img_picto($langs->trans('TooltipEditAndRevertStockMovement'), 'edit');
							print '</a>';
							print '</td>';
						}

						// Action delete line
						if ($permissiontodelete) {
							$href = $_SERVER["PHP_SELF"].'?id='.((int) $object->id).'&action=deleteline&token='.newToken().'&lineid='.((int) $line2['rowid']).'&fk_movement='.((int) $line2['fk_stock_movement']);
							print '<td class="center">';
							print '<a class="reposition" href="'.$href.'">';
							print img_picto($langs->trans('TooltipDeleteAndRevertStockMovement'), 'delete');
							print '</a>';
							print '</td>';
						}

						print '</tr>';
					}

					if (in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
						$i = 1;
						print '<!-- Enter line to consume -->'."\n";
						$maxQty = 1;
						print '<tr data-max-qty="'.$maxQty.'" name="batch_'.$line->id.'_'.$i.'">';
						// Ref
						print '<td><span class="opacitymedium">'.$langs->trans("ToConsume").'</span></td>';
						$preselected = (GETPOSTISSET('qty-'.$line->id.'-'.$i) ? GETPOST('qty-'.$line->id.'-'.$i) : max(0, $line->qty - $alreadyconsumed));
						if ($action == 'consumeorproduce' && !getDolGlobalString('MRP_AUTO_SET_REMAINING_QUANTITIES_TO_BE_CONSUMED') && !GETPOSTISSET('qty-'.$line->id.'-'.$i)) {
							$preselected = 0;
						}

						$disable = '';
						if (getDolGlobalString('MRP_NEVER_CONSUME_MORE_THAN_EXPECTED') && ($line->qty - $alreadyconsumed) <= 0) {
							$disable = 'disabled';
						}

						// input hidden with fk_product of line
						print '<input type="hidden" name="product-'.$line->id.'-'.$i.'" value="'.$line->fk_product.'">';

						// Qty
				// Qty
// Qty (re-applying editability and client-side validation)
print '<td class="right">';
$remaining_to_consume = $line->qty - $alreadyconsumed;
// $preselected is already calculated as: (GETPOSTISSET('qty-'.$line->id.'-'.$i) ? GETPOST('qty-'.$line->id.'-'.$i) : max(0, $line->qty - $alreadyconsumed));
        
print '<input type="number" class="width50 right" id="qtytoconsume-' . $line->id . '-' . $i . '" name="qty-' . $line->id . '-' . $i . '" value="' . $preselected . '" min="0" max="' . $remaining_to_consume . '" step="any" ' . ($remaining_to_consume <= 0 && !GETPOSTISSET('qty-'.$line->id.'-'.$i) ? 'disabled' : '') . '>';
print '</td>';
						// Unit
						print '<td></td>';

						// Cost
						if ($permissiontoupdatecost && getDolGlobalString('MRP_SHOW_COST_FOR_CONSUMPTION')) {
							print '<td></td>';
						}

						// Already consumed
						print '<td></td>';

						// Warehouse
						print '<td>';
						if ($tmpproduct->type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
							if (empty($line->disable_stock_change)) {
								$preselected = (GETPOSTISSET('idwarehouse-'.$line->id.'-'.$i) ? GETPOST('idwarehouse-'.$line->id.'-'.$i) : ($tmpproduct->fk_default_warehouse > 0 ? $tmpproduct->fk_default_warehouse : 'ifone'));
								print $formproduct->selectWarehouses($preselected, 'idwarehouse-'.$line->id.'-'.$i, '', 1, 0, $line->fk_product, '', 1, 0, array(), 'maxwidth200 csswarehouse_'.$line->id.'_'.$i);
							} else {
								print '<span class="opacitymedium">'.$langs->trans("DisableStockChange").'</span>';
							}
						} else {
							print '<span class="opacitymedium">'.$langs->trans("NoStockChangeOnServices").'</span>';
						}
						print '</td>';

						// Stock
						if (isModEnabled('stock')) {
							print '<td></td>';
						}

						// Lot / Batch
						if (isModEnabled('productbatch')) {
							print '<td class="nowraponall">';
							if ($tmpproduct->status_batch) {
								$preselected = (GETPOSTISSET('batch-'.$line->id.'-'.$i) ? GETPOST('batch-'.$line->id.'-'.$i) : '');
								// Input field for consumption batch - list attribute removed and selectLotDataList call removed.
								print '<input type="text" class="width75" name="batch-'.$line->id.'-'.$i.'" value="'.$preselected.'">';
								// print $formproduct->selectLotDataList('batch-'.$line->id.'-'.$i, 0, $line->fk_product, 0, array()); // Datalist REMOVED
							}
							print '</td>';
						}

						// Split
						$type = 'batch';
						print '<td align="right" class="split">';
						print ' '.img_picto($langs->trans('AddStockLocationLine'), 'split.png', 'class="splitbutton" onClick="addDispatchLine('.((int) $line->id).', \''.dol_escape_js($type).'\', \'qtymissingconsume\')"');
						print '</td>';

						// Split All
						print '<td align="right" class="splitall">';
						if (($action == 'consumeorproduce' || $action == 'consumeandproduceall') && $tmpproduct->status_batch == 2) {
							print img_picto($langs->trans('SplitAllQuantity'), 'split.png', 'class="splitbutton splitallbutton field-error-icon" data-max-qty="1" onClick="addDispatchLine('.$line->id.', \'batch\', \'allmissingconsume\')"');
						}
						print '</td>';

						// Edit Line
						if ($object->status == Mo::STATUS_DRAFT) {
							print '<td></td>';
						}

						// Action delete line
						if ($permissiontodelete) {
							print '<td></td>';
						}

						print '</tr>';
					}
				}
			}
		}

		print '</table>';
		print '</div>';

		// default warehouse processing
		print '<script type="text/javascript">
			$(document).ready(function () {
				$("select[name=fk_default_warehouse]").change(function() {
                    var fk_default_warehouse = $("option:selected", this).val();
					$("select[name^=idwarehouse-]").val(fk_default_warehouse).change();
                });
			});
		</script>';

		if (in_array($action, array('consumeorproduce', 'consumeandproduceall')) &&
			getDolGlobalString('STOCK_CONSUMPTION_FROM_MANUFACTURING_WAREHOUSE')) {
			print '<script>$(document).ready(function () {
				$("#fk_default_warehouse").change();
			});</script>';
		}


		// Lines to produce

		print '</div>';
		print '<div class="fichehalfright">';
		print '<div class="clearboth"></div>';

		$nblinetoproduce = 0;
		foreach ($object->lines as $line) {
			if ($line->role == 'toproduce') {
				$nblinetoproduce++;
			}
		}

		$newcardbutton = '';
		$url = $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=addproduceline&token='.newToken();
		$permissiontoaddaproductline = ($object->status != $object::STATUS_PRODUCED && $object->status != $object::STATUS_CANCELED);
		$parameters = array('morecss' => 'reposition');
		if ($action != 'consumeorproduce' && $action != 'consumeandproduceall') {
			if ($nblinetoproduce == 0 || $object->mrptype == 1) {
				$newcardbutton = dolGetButtonTitle($langs->trans('AddNewProduceLines'), '', 'fa fa-plus-circle size15x', $url, '', (int) $permissiontoaddaproductline, $parameters);
			}
		}

		print load_fiche_titre($langs->trans('Production'), $newcardbutton, '', 0, '', '');

		print '<div class="div-table-responsive-no-min">';
		print '<table id="tablelinestoproduce" class="noborder noshadow nobottom centpercent">';

		print '<tr class="liste_titre trheight5em">';
		// Product
		print '<td>'.$langs->trans("Product").'</td>';
		// Qty
		print '<td class="right">'.$langs->trans("Qty").'</td>';
		/// Unit
		if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
			print '<td class="right">'.$langs->trans("Unit").'</td>';
		}
		// Cost price
		if ($permissiontoupdatecost) {
			if (empty($bomcostupdated)) {
				print '<td class="right classfortooltip" title="'.$langs->trans("AmountUsedToUpdateWAP").'">';
				print $langs->trans("UnitCost");
				print '</td>';
			} else {
				print '<td class="right classfortooltip" title="'.$langs->trans("AmountUsedToUpdateWAP").'">';
				print $langs->trans("ManufacturingPrice");
				print '</td>';
			}
		}
		// Already produced
		print '<td class="right classfortooltip" title="'.$langs->trans("QtyAlreadyProduced").'">';
		print $langs->trans("QtyAlreadyProducedShort");
		print '</td>';
		// Warehouse
		print '<td>';
		if ($collapse || in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
			print $langs->trans("Warehouse");
		}
		print '</td>';

		// Lot
		if (isModEnabled('productbatch')) {
			print '<td>';
			if ($collapse || in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
				print $langs->trans("Batch");
			}
			print '</td>';

			// Split
			print '<td></td>';

			// Split All
			print '<td></td>';
		}

		// Action delete
		if ($permissiontodelete) {
			print '<td></td>';
		}

		print '</tr>';

		if ($action == 'addproduceline') {
			print '<!-- Add line to produce -->'."\n";
			print '<tr class="liste_titre">';

			// Product
			print '<td>';
			print $form->select_produits(0, 'productidtoadd', '', 0, 0, -1, 2, '', 1, array(), 0, '1', 0, 'maxwidth300');
			print '</td>';
			// Qty
			print '<td class="right"><input type="text" name="qtytoadd" value="1" class="width50 right"></td>';
			//Unit
			if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
				print '<td></td>';
			}
			// Cost price
			if ($permissiontoupdatecost) {
				print '<td></td>';
			}
			// Action (cost price + already produced)
			print '<td colspan="2">';
			print '<input type="submit" class="button buttongen button-add" name="addproducelinebutton" value="'.$langs->trans("Add").'">';
			print '<input type="submit" class="button buttongen button-cancel" name="canceladdproducelinebutton" value="'.$langs->trans("Cancel").'">';
			print '</td>';
			// Lot - serial
			if (isModEnabled('productbatch')) {
				print '<td></td>';

				// Split
				print '<td></td>';

				// Split All
				print '<td></td>';
			}
			// Action delete
			if ($permissiontodelete) {
				print '<td></td>';
			}
			print '</tr>';
		}

		if (!empty($object->lines)) {
			$nblinetoproduce = 0;
			foreach ($object->lines as $line) {
				if ($line->role == 'toproduce') {
					$nblinetoproduce++;
				}
			}

			$nblinetoproducecursor = 0;
			foreach ($object->lines as $line) {
				if ($line->role == 'toproduce') {
					$i = 1;

					$nblinetoproducecursor++;

					$tmpproduct = new Product($db);
					$tmpproduct->fetch($line->fk_product);

					$is_category_24_product = false;
					$sql_cat = "SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product ";
					$sql_cat .= " WHERE fk_categorie = 317 AND fk_product = ".((int) $line->fk_product);
					$resql_cat = $db->query($sql_cat);
					if ($resql_cat) {
						if ($db->num_rows($resql_cat) > 0) {
							$is_category_24_product = true;
						}
						$db->free($resql_cat); // Free the result
					} else {
						// Optional: Log error if query fails
						dol_syslog("Error checking product category linkage for product ID ".$line->fk_product, LOG_ERR);
					}

					$arrayoflines = $object->fetchLinesLinked('produced', $line->id);
					$alreadyproduced = 0;
					foreach ($arrayoflines as $line2) {
						$alreadyproduced += $line2['qty'];
					}

					$suffix = '_'.$line->id;
					print '<!-- Line to dispatch '.$suffix.' (toproduce) -->'."\n";
					// hidden fields for js function
					print '<input id="qty_ordered'.$suffix.'" type="hidden" value="'.$line->qty.'">';
					print '<input id="qty_dispatched'.$suffix.'" type="hidden" value="'.$alreadyproduced.'">';

					print '<tr>';
					// Product
					print '<td>'.$tmpproduct->getNomUrl(1);
					print '<br><span class="opacitymedium small">'.$tmpproduct->label.'</span>';
					print '</td>';
					// Qty
					print '<td class="right">'.$line->qty.'</td>';
					// Unit
					if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
						print '<td class="right">'.measuringUnitString($line->fk_unit, '', '', 1).'</td>';
					}
					// Cost price
		$manufacturingcost = 0;
$manufacturingcostsrc = '';
if ($object->mrptype == 0) { // Manufacture type MO
    $manufacturingcost = 0;
    $manufacturingcostsrc = '';

    // Try to use updated BOM cost first
    $manufacturingcost = $bomcostupdated;
    $manufacturingcostsrc = $langs->trans("CalculatedFromProductsToConsume");

    // Fallback to BOM base cost if updated cost is empty
    if (empty($manufacturingcost)) {
        $manufacturingcost = $bomcost;
        $manufacturingcostsrc = $langs->trans("ValueFromBom");
    }

    // Fallback to product cost price if BOM costs are empty
    if (empty($manufacturingcost)) {
        $manufacturingcost = price2num($tmpproduct->cost_price, 'MU');
        $manufacturingcostsrc = $langs->trans("CostPrice");
    }

    // Final fallback to PMP value if cost price is empty
    if (empty($manufacturingcost)) {
        $manufacturingcost = price2num($tmpproduct->pmp, 'MU');
        $manufacturingcostsrc = $langs->trans("PMPValue");
    }
}

// Override manufacturing cost to PMP if product was consumed with negative quantity
if (isset($productsWithNegativeConsumptionQty[$line->fk_product])) {
    include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
    $productConsumed = new Product($db);
    $productConsumed->fetch($line->fk_product);

    $manufacturingcost = $productConsumed->pmp > 0 ? price2num($productConsumed->pmp, 'MU') : 0;
    $manufacturingcostsrc = $langs->trans("SetToPMPDueToNegativeConsumptionQty");
}

// Output the manufacturing cost in a table cell with tooltip showing the cost source
print '<td class="right nowraponall" title="'.dol_escape_htmltag($manufacturingcostsrc).'">';
print price($manufacturingcost ? $manufacturingcost : 0);
print '</td>';


					// Already produced
					print '<td class="right nowraponall">';
					if ($alreadyproduced) {
						print '<script>';
						print 'jQuery(document).ready(function() {
							jQuery("#expandtoproduce'.$line->id.'").click(function() {
								console.log("Expand mrp_production line '.$line->id.'");
								jQuery(".expanddetailtoproduce'.$line->id.'").toggle();';
						if ($nblinetoproduce == $nblinetoproducecursor) {
							print 'if (jQuery("#tablelinestoproduce").hasClass("nobottom")) { jQuery("#tablelinestoproduce").removeClass("nobottom"); } else { jQuery("#tablelinestoproduce").addClass("nobottom"); }';
						}
						print '
							});
						});';
						print '</script>';
						if (empty($conf->use_javascript_ajax)) {
							print '<a href="'.$_SERVER["PHP_SELF"].'?collapse='.$collapse.','.$line->id.'">';
						}
						print img_picto($langs->trans("ShowDetails"), "chevron-down", 'id="expandtoproduce'.$line->id.'"');
						if (empty($conf->use_javascript_ajax)) {
							print '</a>';
						}
					}
					print ' '.$alreadyproduced;
					print '</td>';
					// Warehouse
					print '<td>';
					print '</td>';
					// Lot
					if (isModEnabled('productbatch')) {
						print '<td></td>';

						// Split
						print '<td></td>';

						// Split All
						print '<td></td>';
					}
					// Delete
					if ($permissiontodelete) {
						if ($line->origin_type == 'free') {
							$href = $_SERVER["PHP_SELF"];
							$href .= '?id='.$object->id;
							$href .= '&action=deleteline';
							$href .= '&token='.newToken();
							$href .= '&lineid='.$line->id;
							print '<td class="center">';
							print '<a class="reposition" href="'.$href.'">';
							print img_picto($langs->trans('TooltipDeleteAndRevertStockMovement'), "delete");
							print '</a>';
							print '</td>';
						} else {
							print '<td></td>';
						}
					}
					print '</tr>';

					// Show detailed of already consumed with js code to collapse
					foreach ($arrayoflines as $line2) {
						print '<tr class="expanddetailtoproduce'.$line->id.' hideobject opacitylow">';
						// Product
						print '<td>';
						$tmpstockmovement->id = $line2['fk_stock_movement'];
						print '<a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?search_ref='.$tmpstockmovement->id.'">'.img_picto($langs->trans("StockMovement"), 'movement', 'class="paddingright"').'</a>';
						print dol_print_date($line2['date'], 'dayhour', 'tzuserrel');
						print '</td>';
						// Qty
						print '<td></td>';
						// Unit
						if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
							print '<td></td>';
						}
						// Cost price
						if ($permissiontoupdatecost) {
							print '<td></td>';
						}
						// Already produced
						print '<td class="right">'.$line2['qty'].'</td>';
						// Warehouse
						print '<td class="tdoverflowmax150">';
						if ($line2['fk_warehouse'] > 0) {
							$result = $tmpwarehouse->fetch($line2['fk_warehouse']);
							if ($result > 0) {
								print $tmpwarehouse->getNomUrl(1);
							}
						}
						print '</td>';
						// Lot
						if (isModEnabled('productbatch')) {
							print '<td>';
							if ($line2['batch'] != '') {
								$tmpbatch->fetch(0, $line2['fk_product'], $line2['batch']);
								print $tmpbatch->getNomUrl(1);
							}
							print '</td>';

							// Split
							print '<td></td>';

							// Split All
							print '<td></td>';
						}
						// Action delete
						if ($permissiontodelete) {
							print '<td></td>';
						}
						print '</tr>';
					}

					if (in_array($action, array('consumeorproduce', 'consumeandproduceall'))) {
						print '<!-- Enter line to produce -->'."\n";
						$maxQty = 1;

						// Define $is_category_24_product_server here for the scope of rendering the input row
						// This is needed for the data-is-category-24 attribute and JS logic.
						// This definition was previously only in the action processing block.
						$is_category_24_product_server_for_display = false; // Use a distinct name to avoid confusion if the other is in scope, though it shouldn't be.
						$sql_cat_server_display = "SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_categorie = 317 AND fk_product = ".((int) $line->fk_product);
						$resql_cat_server_display = $db->query($sql_cat_server_display);
						if ($resql_cat_server_display) {
							if ($db->num_rows($resql_cat_server_display) > 0) $is_category_24_product_server_for_display = true;
							$db->free($resql_cat_server_display);
						} else {
							dol_syslog("Error checking product category (server-side display) for product ID ".$line->fk_product, LOG_ERR);
						}

						// Add data-is-category-317 attribute for JS
						$is_cat_24_for_js = $is_category_24_product_server_for_display ? '1' : '0';
						print '<tr data-product-id="'.(!empty($line->fk_product) ? $line->fk_product : '0').'" data-is-category-317="'.$is_cat_24_for_js.'" data-mo-ref="'.dol_escape_htmltag($object->ref).'" data-max-qty="'.$maxQty.'" name="batch_'.$line->id.'_'.$i.'">';
						// Product
						print '<td><span class="opacitymedium">'.$langs->trans("ToProduce").'</span></td>';
						$preselected = (GETPOSTISSET('qtytoproduce-'.$line->id.'-'.$i) ? GETPOST('qtytoproduce-'.$line->id.'-'.$i) : max(0, $line->qty - $alreadyproduced));
						if ($action == 'consumeorproduce' && !GETPOSTISSET('qtytoproduce-'.$line->id.'-'.$i)) {
							$preselected = 0;
						}
						// Qty (re-applying editability and client-side validation)
        print '<td class="right">';
        $remaining_to_produce = $line->qty - $alreadyproduced;
        // $preselected is already calculated as: (GETPOSTISSET('qtytoproduce-'.$line->id.'-'.$i) ? GETPOST('qtytoproduce-'.$line->id.'-'.$i) : max(0, $line->qty - $alreadyproduced));
        
        print '<input type="number" class="width50 right" id="qtytoproduce-'.$line->id.'-'.$i.'" name="qtytoproduce-'.$line->id.'-'.$i.'" value="'.$preselected.'" min="0" max="' . $remaining_to_produce . '" step="any" ' . ($remaining_to_produce <= 0 && !GETPOSTISSET('qtytoproduce-'.$line->id.'-'.$i) ? 'disabled' : '') . '>';
        print '</td>';
						//Unit
						if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
							print '<td class="right"></td>';
						}
						// Cost
					if ($permissiontoupdatecost) {
    // Defined $manufacturingcost
    $manufacturingcost = 0;
    if ($object->mrptype == 0) { // If MO is a "Manufacture" type (and not "Disassemble")
        $manufacturingcost = $bomcostupdated;
        if (empty($manufacturingcost)) {
            $manufacturingcost = $bomcost;
        }
        if (empty($manufacturingcost)) {
            $manufacturingcost = price2num($tmpproduct->cost_price, 'MU');
        }
        if (empty($manufacturingcost)) {
            $manufacturingcost = price2num($tmpproduct->pmp, 'MU');
        }
    }

    $force_price_to_zero = isset($productsWithNegativeConsumptionQty[$line->fk_product]);

    if ($force_price_to_zero) {
        $manufacturingcost = 0;
    }

    if ($tmpproduct->type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
        $default_price_for_input = $manufacturingcost ? price($manufacturingcost) : price(0);

        if (GETPOSTISSET('pricetoproduce-'.$line->id.'-'.$i)) {
            $preselected = GETPOST('pricetoproduce-'.$line->id.'-'.$i);
            if ($force_price_to_zero) { // If our rule mandates zero, override submitted value
                $preselected = price(0);
            }
        } else { // If not from GETPOST, use the calculated (and possibly forced to zero) value
            $preselected = $default_price_for_input;
        }
        print '<td class="right">';
        if ($user->admin) { // Show editable input for admins
            print '<input type="text" class="width75 right" name="pricetoproduce-'.$line->id.'-'.$i.'" value="'.$preselected.'">';
        } else { // For non-admins, show price as text and include hidden input
            print '<span class="width75 right">'.price($preselected).'</span>';
            print '<input type="hidden" name="pricetoproduce-'.$line->id.'-'.$i.'" value="'.$preselected.'">';
        }
        print '</td>';
    } else { // Hidden field for non-product/service types
        $hidden_value = $manufacturingcost ? $manufacturingcost : 0; // Use numeric value
        if ($force_price_to_zero) {
            $hidden_value = 0; // Force numeric 0
        }
        print '<td>';
        if ($user->admin) { // Show hidden input for admins
            print '<input type="hidden" class="width50 right" name="pricetoproduce-'.$line->id.'-'.$i.'" value="'. $hidden_value.'">';
        } else { // For non-admins, show as text and include hidden input
            print '<span class="width50 right">'.price($hidden_value).'</span>';
            print '<input type="hidden" name="pricetoproduce-'.$line->id.'-'.$i.'" value="'. $hidden_value.'">';
        }
        print '</td>';
    }
}
						// Already produced
						print '<td></td>';
						// Warehouse
						print '<td>';
						if ($tmpproduct->type == Product::TYPE_PRODUCT || getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
							$preselected = (GETPOSTISSET('idwarehousetoproduce-'.$line->id.'-'.$i) ? GETPOST('idwarehousetoproduce-'.$line->id.'-'.$i) : ($object->fk_warehouse > 0 ? $object->fk_warehouse : 'ifone'));
							print $formproduct->selectWarehouses($preselected, 'idwarehousetoproduce-'.$line->id.'-'.$i, '', 1, 0, $line->fk_product, '', 1, 0, array(), 'maxwidth200 csswarehouse_'.$line->id.'_'.$i);
						} else {
							print '<span class="opacitymedium">'.$langs->trans("NoStockChangeOnServices").'</span>';
						}
						print '</td>';
						// Lot
						if (isModEnabled('productbatch')) {
							print '<td>';
							if ($tmpproduct->status_batch) {
								$batch_input_value = (GETPOSTISSET('batchtoproduce-'.$line->id.'-'.$i) ? dol_escape_htmltag(GETPOST('batchtoproduce-'.$line->id.'-'.$i)) : '');
								$batch_input_readonly = '';
								$batch_input_value = (GETPOSTISSET('batchtoproduce-'.$line->id.'-'.$i) ? dol_escape_htmltag(GETPOST('batchtoproduce-'.$line->id.'-'.$i)) : '');

								// $total_quantity_for_line needs to be defined for this scope as well.
								// It's typically $line->qty for the "toproduce" line.
								$total_quantity_for_line_display = $line->qty;


								// Use $is_category_24_product_server_for_display for the rendering logic
								if ($is_category_24_product_server_for_display && $total_quantity_for_line_display > 1) {
									// Value will be set by JS, or could be pre-filled if desired, but JS will manage it
									// For now, leave it empty here, JS will fill and make readonly.
									// $batch_input_value = ''; // Let JS handle this for now.
									$batch_input_readonly = 'readonly="readonly"'; // JS will also set this, but good for consistency
								} elseif ($is_category_24_product_server_for_display && $total_quantity_for_line_display == 1) {
									$batch_input_value = dol_escape_htmltag($object->ref); // Use MO reference
									$batch_input_readonly = 'readonly="readonly"';
								} elseif ($line->fk_product == 483) { // Handled by JS primarily
									// Value and readonly state will be managed by existing JS for product 31
									// No specific value override here, let JS take precedence.
								}
								// For other products, $batch_input_value remains as submitted or empty, and $batch_input_readonly remains empty.
								
								print '<input type="text" class="width75" id="batchtoproduce-'.$line->id.'-'.$i.'" name="batchtoproduce-'.$line->id.'-'.$i.'" value="'.$batch_input_value.'" '.$batch_input_readonly.'>';
							}
							print '</td>';
							// Batch number in same column than the stock movement picto
							if ($tmpproduct->status_batch) {
								$type = 'batch';
								print '<td align="right" class="split">';
								print img_picto($langs->trans('AddStockLocationLine'), 'split.png', 'class="splitbutton" onClick="addDispatchLine('.$line->id.', \''.$type.'\', \'qtymissing\')"');
								print '</td>';

								print '<td align="right"  class="splitall">';
								if (($action == 'consumeorproduce' || $action == 'consumeandproduceall') && $tmpproduct->status_batch == 2) {
									print img_picto($langs->trans('SplitAllQuantity'), 'split.png', 'class="splitbutton splitallbutton field-error-icon" onClick="addDispatchLine('.$line->id.', \'batch\', \'alltoproduce\')"');
								} //
								print '</td>';
							} else {
								print '<td></td>';

								print '<td></td>';
							}
						}

						// Action delete
						print '<td></td>';

						print '</tr>';
					}
				}
			}
		}

		print '</table>';
		print '</div>';

		print '</div>';
		print '</div>';
	}

	if (in_array($action, array('consumeorproduce', 'consumeandproduceall', 'addconsumeline', 'addproduceline', 'editline'))) { // Added 'editline' and 'addproduceline'
		print "</form>\n";
	} ?>

		<script  type="text/javascript" language="javascript">

			$(document).ready(function() {
				//Consumption : When a warehouse is selected, only the lot/serial numbers that are available in it are offered
				updateselectbatchbywarehouse();
				//Consumption : When a lot/serial number is selected and it is only available in one warehouse, the warehouse is automatically selected
				updateselectwarehousebybatch();
			});

			function updateselectbatchbywarehouse() {
				$(document).on('change', "select[name*='idwarehouse']", function () {
					console.log("We change warehouse so we update the list of possible batch number");

					var selectwarehouse = $(this);

					var selectbatch_name = selectwarehouse.attr('name').replace('idwarehouse', 'batch');
					var selectbatch = $("datalist[id*='" + selectbatch_name + "']");
                    var inputbatch = $("input[list='" + selectbatch_name + "']");
					var selectedbatch = inputbatch.val();

					var product_element_name = selectwarehouse.attr('name').replace('idwarehouse', 'product');

					$.ajax({
						type: "POST",
						url: "<?php echo DOL_URL_ROOT . '/mrp/ajax/interface.php'; ?>",
						data: {
							action: "updateselectbatchbywarehouse",
							permissiontoproduce: <?php echo $permissiontoproduce ? '1' : '0'; ?>,
							warehouse_id: $(this).val(),
							token: '<?php echo newToken(); ?>',
							product_id: $("input[name='" + product_element_name + "']").val()
						}
					}).done(function (data) {

						selectbatch.empty();

						if (typeof data == "object") {
							console.log("data is already type object, no need to parse it");
						} else {
							console.log("data is type "+(typeof data));
							data = JSON.parse(data);
						}

						selectbatch.append($('<option>', {
							value: '',
						}));

						$.each(data, function (key, value) {

							if(selectwarehouse.val() == -1) {
								var label = key + " (<?php echo $langs->trans('Stock total') ?> : " + value + ")";
							} else {
								var label = key + " (<?php echo $langs->trans('Stock') ?> : " + value + ")";
							}

							if(key === selectedbatch) {
								var option ='<option value="'+key+'" selected>'+ label +'</option>';
							} else {
								var option ='<option value="'+key+'">'+ label +'</option>';
							}

							selectbatch.append(option);
						});
					});
				});
			}

			function updateselectwarehousebybatch() {
				$(document).on('change', 'input[name*=batch]', function(){
					console.log("We change batch so we update the list of possible warehouses");

					var selectbatch = $(this);

					var selectwarehouse_name = selectbatch.attr('name').replace('batch', 'idwarehouse');
					var selectwarehouse = $("select[name*='" + selectwarehouse_name + "']");
					var selectedwarehouse = selectwarehouse.val();

					if(selectedwarehouse != -1){
						return;
					}

					var product_element_name = selectbatch.attr('name').replace('batch', 'product');

					$.ajax({
						type: "POST",
						url: "<?php echo DOL_URL_ROOT . '/mrp/ajax/interface.php'; ?>",
						data: {
							action: "updateselectwarehousebybatch",
							permissiontoproduce: <?php echo $permissiontoproduce ? '1' : '0'; ?>,
							batch: $(this).val(),
							token: '<?php echo newToken(); ?>',
							product_id: $("input[name='" + product_element_name + "']").val()
						}
					}).done(function (data) {

						if (typeof data == "object") {
							console.log("data is already type object, no need to parse it");
						} else {
							console.log("data is type "+(typeof data));
							data = JSON.parse(data);
						}

						if(data != 0){
							selectwarehouse.val(data).change();
						}
					});
				});
			}

		</script>

	<?php
}

// End of page

?>
<script type="text/javascript">
  var currentMoRef = "<?php echo dol_escape_js($object->ref); ?>";

  $(document).ready(function() {
    // Target rows within the 'to produce' table that have batch inputs.
    // These rows now have data-product-id, data-is-category-317 and data-mo-ref attributes.
    $('tr[data-mo-ref]').each(function() {
        var $row = $(this);
        var productId = $row.data('product-id');
        var isCategory317 = $row.data('is-category-317') == '1';
        var moRefFromDataAttr = $row.data('mo-ref'); 

        // Find the input field by its ID, which should now be set in PHP
        var lineIdInputName = $row.find('input[name^="batchtoproduce-"]').attr('name');
        var inputFieldId = '';
        if (lineIdInputName) {
            var idParts = lineIdInputName.match(/batchtoproduce-(\d+-\d+)/);
            if (idParts && idParts[1]) {
                 inputFieldId = 'batchtoproduce-' + idParts[1];
            }
        }
        var $inputField = $('#' + inputFieldId);


        if ($inputField.length) {
            var lineIdMatch = inputFieldId.match(/batchtoproduce-(\d+)-/);

            if (lineIdMatch && lineIdMatch[1]) {
                var lineId = lineIdMatch[1];
                var $qtyOrderedElement = $('#qty_ordered_' + lineId); // This is total planned qty for the MO line
                
                if ($qtyOrderedElement.length > 0 && $qtyOrderedElement.val() !== "") {
                    var totalQtyForLine = parseFloat($qtyOrderedElement.val());

                    if (productId == 483 && totalQtyForLine > 1) {
                        $inputField.val(''); 
                        $inputField.prop('readonly', true);
                        if ($inputField.next('.auto-serial-info').length === 0) {
                            $inputField.after('<small class="auto-serial-info"> <?php echo $langs->trans("AutoGeneratedSerialQtyGreaterThanOne"); ?></small>');
                        }
                    } else if (productId == 483 && totalQtyForLine == 1) {
                        if (typeof moRefFromDataAttr !== 'undefined' && moRefFromDataAttr) {
                            $inputField.val(moRefFromDataAttr);
                        } else if (typeof currentMoRef !== 'undefined') {
                             $inputField.val(currentMoRef);
                        }
                        $inputField.prop('readonly', true);
                        $inputField.next('.auto-serial-info').remove(); 
                    } else if (isCategory317 && totalQtyForLine > 1) {
                        $inputField.val(''); // Clear value, will display info message
                        $inputField.prop('readonly', true);
                        var serialInfoMsg = "<?php echo $langs->trans("SerialsAutoGeneratedMessage"); ?>".replace('%s', moRefFromDataAttr + '-1, ' + moRefFromDataAttr + '-2...');
                        if ($inputField.next('.auto-serial-info').length === 0) {
                            $inputField.after('<small class="auto-serial-info"> ' + serialInfoMsg + '</small>');
                        } else { // Update if already there
                            $inputField.next('.auto-serial-info').html(' ' + serialInfoMsg);
                        }
                    } else if (isCategory317 && totalQtyForLine == 1) {
                        // Batch is MO ref, readonly
                        if (typeof moRefFromDataAttr !== 'undefined' && moRefFromDataAttr) {
                            $inputField.val(moRefFromDataAttr);
                        }
                        $inputField.prop('readonly', true);
                        $inputField.next('.auto-serial-info').remove();
                    } else { // Not product 31 and not (category 24 with qty > 1)
                        // Ensure field is editable and no message is shown if it's not special case
                        // Batch input might have been set to readonly by PHP if it was cat 24 qty 1, ensure it is not if conditions change.
                        // However, if it's cat 24 qty 1, it should remain readonly with MO ref.
                        // The PHP part already sets readonly for cat 24 qty 1.
                        // So this 'else' means: not product 31, not (cat 24 qty > 1), not (cat 24 qty == 1)
                        // This means it's a standard product OR cat 24 but something went wrong with qty check.
                        // For standard products, it should be editable.
                        if (!isCategory317) { // Only make editable if not category 317 at all. Cat 317 Qty 1 is handled above.
                           $inputField.prop('readonly', false);
                        }
                        $inputField.next('.auto-serial-info').remove(); 
                    }
                } else { // Fallback if qty_ordered_LINEID is not found or empty
                    console.warn("JavaScript: Could not find #qty_ordered_" + lineId + " or it was empty for product " + productId + ". Batch field behavior might be unexpected.");
                    // Default behavior for product 31 if quantity unknown (treat as 1)
                    if (productId == 483) {
                        if (typeof moRefFromDataAttr !== 'undefined' && moRefFromDataAttr) {
                            $inputField.val(moRefFromDataAttr);
                        } else if (typeof currentMoRef !== 'undefined') {
                            $inputField.val(currentMoRef);
                        }
                        $inputField.prop('readonly', true);
                    } else if (isCategory317) { // Default for cat 317 if quantity unknown (treat as 1)
                         if (typeof moRefFromDataAttr !== 'undefined' && moRefFromDataAttr) {
                            $inputField.val(moRefFromDataAttr);
                        }
                        $inputField.prop('readonly', true);
                    }
                    // For other products, leave as is (should be editable by default)
                    $inputField.next('.auto-serial-info').remove();
                }
            } else { // Fallback if lineId cannot be parsed from input field ID
                 console.warn("JavaScript: Could not parse lineId from input ID '" + inputFieldId + "'. Batch field behavior might be unexpected.");
                 // Default behavior for product 31 if lineId unknown
                 if (productId == 483 ) {
                     if (typeof moRefFromDataAttr !== 'undefined' && moRefFromDataAttr) { $inputField.val(moRefFromDataAttr); }
                     else if (typeof currentMoRef !== 'undefined') { $inputField.val(currentMoRef); }
                     $inputField.prop('readonly', true);
                 } else if (isCategory317) { // Default for cat 317 if lineId unknown
                     if (typeof moRefFromDataAttr !== 'undefined' && moRefFromDataAttr) { $inputField.val(moRefFromDataAttr); }
                     $inputField.prop('readonly', true);
                 }
                 $inputField.next('.auto-serial-info').remove();
            }
        }
    });
  });
</script>
<?php

llxFooter();
$db->close();



