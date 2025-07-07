<?php
/* Copyright (C) 2005       Rodolphe Quiedeville		rodolphe@quiedeville.org
Copyright (C) 2005-2012  Laurent Destailleur			eldy@users.sourceforge.net
Copyright (C) 2005-2012  Regis Houssin				regis.houssin@inodbox.com
Copyright (C) 2014-2015  Marcos García				marcosgdf@gmail.com
Copyright (C) 2018-2024  Frédéric France				frederic.france@free.fr
Copyright (C) 2023 		Charlene Benke				charlene@patas-monkey.com
Copyright (C) 2024		MDW							mdeweerd@users.noreply.github.com
Copyright (C) 2024	    Nick Fragoulis
Copyright (C) 2024		Alexandre Spangaro			alexandre@inovea-conseil.com

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for a copy of the GNU General Public License
along with this program. If not, see https://www.gnu.org/licenses/.
or see https://www.gnu.org/
*/

/**
 * \file       htdocs/core/modules/expedition/doc/pdf_espadon.modules.php
 * \ingroup    expedition
 * \brief      Class file allowing Espadon shipping template generation with advanced features.
 */
require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
if (isModEnabled('mrp')) {
	require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
}
if (isModEnabled('commande')) {
	require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
}


/**
 * Class to build sending documents with model Espadon
 */
class pdf_espadon extends ModelePdfExpedition
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;
	/**
	 * @var string model name
	 */
	public $name;
	/**
	 * @var string model description (short text)
	 */
	public $description;
	/**
	 * @var int     Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;
	/**
	 * @var string document type
	 */
	public $type;
	/**
	 * Dolibarr version of the loaded document
	 * @var string Version, possible values are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'
	 */
	public $version = 'dolibarr';

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs, $mysoc;

		$this->db = $db;
		$this->name = "espadon";
		$this->description = $langs->trans("DocumentModelStandardPDF");
		$this->update_main_doc_field = 1;
		$this->type = 'pdf';

		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);

		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
		$this->corner_radius = getDolGlobalInt('MAIN_PDF_FRAME_CORNER_RADIUS', 0);

		$this->option_logo = 1;
		$this->option_draft_watermark = 1;
		$this->watermark = '';

		if (empty($mysoc)) {
			dol_syslog(get_class($this).'::__construct() Global $mysoc should not be null.'. getCallerInfoString(), LOG_ERR);
			return;
		}
		$this->emetteur = $mysoc;
		if (empty($this->emetteur->country_code)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2);
		}
		$this->tabTitleHeight = 5;
	}

	/**
	 * Function to build pdf onto disk
	 *
	 * @param		Expedition	$object			    Object shipping to generate (or id if old method)
	 * @param		Translate	$outputlangs		Lang output object
	 * @param		string		$srctemplatepath	Full path of source filename for generator using a template file
	 * @param		int<0,1>	$hidedetails		Do not show line details
	 * @param		int<0,1>	$hidedesc			Do not show desc
	 * @param		int<0,1>	$hideref			Do not show ref
	 * @return		int<-1,1>						1 if OK, <=0 if KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $user, $conf, $langs, $hookmanager;

		$object->fetch_thirdparty();
		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (getDolGlobalString('MAIN_USE_FPDF')) {
			$outputlangs->charset_output = 'ISO-8859-1';
		}
		$outputlangs->loadLangs(array("main", "bills", "orders", "products", "dict", "companies", "other", "propal", "deliveries", "sendings", "productbatch", "compta", "mrp"));

		if ($object->statut == $object::STATUS_DRAFT && getDolGlobalString('SHIPPING_DRAFT_WATERMARK')) {
			$this->watermark = getDolGlobalString('SHIPPING_DRAFT_WATERMARK');
		}

		$nblines = count($object->lines);

		// Image detection logic
		$realpatharray = array();
		$this->atleastonephoto = false;
		if (getDolGlobalString('MAIN_GENERATE_SHIPMENT_WITH_PICTURE')) {
			$objphoto = new Product($this->db);
			for ($i = 0; $i < $nblines; $i++) {
				if (empty($object->lines[$i]->fk_product)) continue;
				$objphoto->fetch($object->lines[$i]->fk_product);
				if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
					$pdir = get_exdir($object->lines[$i]->fk_product, 2, 0, 0, $objphoto, 'product').$object->lines[$i]->fk_product."/photos/";
					$dir = $conf->product->dir_output.'/'.$pdir;
				} else {
					$pdir = get_exdir(0, 0, 0, 0, $objphoto, 'product');
					$dir = $conf->product->dir_output.'/'.$pdir;
				}
				$realpath = '';
				$arephoto = false;
				foreach ($objphoto->liste_photos($dir, 1) as $key => $obj) {
					$filename = (!getDolGlobalInt('CAT_HIGH_QUALITY_IMAGES') && $obj['photo_vignette']) ? $obj['photo_vignette'] : $obj['photo'];
					$realpath = $dir.$filename;
					$arephoto = true;
					$this->atleastonephoto = true;
					break;
				}
				if ($realpath && $arephoto) $realpatharray[$i] = $realpath;
			}
		}

		if ($conf->expedition->dir_output) {
			if ($object->specimen) {
				$dir = $conf->expedition->dir_output."/sending";
				$file = $dir."/SPECIMEN.pdf";
			} else {
				$expref = dol_sanitizeFileName($object->ref);
				$dir = $conf->expedition->dir_output."/sending/".$expref;
				$file = $dir."/".$expref."_garantie.pdf";
			}

			if (!file_exists($dir) && dol_mkdir($dir) < 0) {
				$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
				return 0;
			}

			if (file_exists($dir)) {
				// PDF Init
				if (!is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager = new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
				global $action;
				$reshook = $hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);
				$nblines = is_array($object->lines) ? count($object->lines) : 0;

				$pdf = pdf_getInstance($this->format);
				$default_font_size = pdf_getPDFFontSize($outputlangs);

				// Define height constants for layout management
				$heightforfooter = 15; // Space for the page number footer
				$heightforinfotot = 8; // Height for the totals area
				$heightforfreetext = 5; // Height for free text (not used here but good practice)

				// Set auto page break with a margin of 0 to manage it manually
				$pdf->SetAutoPageBreak(true, 0);

				if (class_exists('TCPDF')) {
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($outputlangs));

				if (!getDolGlobalString('MAIN_DISABLE_FPDI') && getDolGlobalString('MAIN_ADD_PDF_BACKGROUND')) {
					$pagecount = $pdf->setSourceFile($conf->mycompany->dir_output.'/' . getDolGlobalString('MAIN_ADD_PDF_BACKGROUND'));
					$tplidx = $pdf->importPage(1);
				}

				$pdf->Open();
				$pagenb = 0;
				$pdf->SetDrawColor(128, 128, 128);

				if (method_exists($pdf, 'AliasNbPages')) {
					$pdf->AliasNbPages();
				}

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("Shipment"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) $pdf->SetCompression(false);
				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);

				// New page
				$pdf->AddPage();
				if (!empty($tplidx)) $pdf->useTemplate($tplidx);
				$pagenb++;
				$top_shift = $this->_pagehead($pdf, $object, 1, $outputlangs);
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell(0, 3, '');
				$pdf->SetTextColor(0, 0, 0);

				$tab_top = 100; // Position of top of table on first page
				$tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 42 + $top_shift : 10);
				$tab_height = $this->page_hauteur - $tab_top - $heightforfooter - $heightforinfotot;

				// Prepare the column definitions
				$this->prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

				// Simulate drawing the titles to get their height
				$pdf->startTransaction();
				$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs);
				$pdf->rollbackTransaction(true);

				$nexY = $tab_top + $this->tabTitleHeight;

				// Draw table header for the first page
				$this->_tableau($pdf, $tab_top, 0, 0, $outputlangs, 0, 0);

				$pageposbeforeprintlines = $pdf->getPage();
				$grand_total_ht = 0;
				// --- START: Add Stamp Image ---
// This path points to your company's logo directory.
$stamp_path = $conf->mycompany->dir_output . '/logos/stamp.png';

if (is_readable($stamp_path)) {
    // You can adjust the size and position of the stamp here (values are in mm)
    $stamp_width = 70;
    $stamp_height = 60;
    $stamp_x = $this->page_largeur - $this->marge_droite - $stamp_width - 10; // Position on the right
    $stamp_y = $tab_top; // Position aligned with the top of the table

    // Set the opacity to 60% (0.6)
    $pdf->setAlpha(0.8); 

    // This line adds the image to the PDF
    $pdf->Image($stamp_path, $stamp_x, $stamp_y, $stamp_width, $stamp_height, 'PNG');

    // Reset alpha to 1 (fully opaque) for subsequent elements if needed
    $pdf->setAlpha(1); 
}
// --- END: Add Stamp Image ---

				// Loop on each line
				for ($i = 0; $i < $nblines; $i++) {
					$curY = $nexY;
					$pageposbefore = $pdf->getPage();

					// --- START: Proactive Page Break Logic ---
					$line_height_estimation = 6; // Minimum height for a simple line

					// Estimate description height
					$pdf->startTransaction();
					$pdf->SetFont('', '', $default_font_size - 1);
					$text_to_print = $this->getColDescContent($object, $i, $outputlangs, $hideref, $hidedesc);
					$line_height_estimation = $pdf->getStringHeight($this->cols['desc']['width'], $text_to_print);
					$pdf->rollbackTransaction(true);

					// Add height for BOM components if they exist
					$bom_components = array();
					if (isModEnabled('mrp') && $object->lines[$i]->fk_product == 483) {
						if (preg_match('/([A-Za-z0-9-]+-?\d+)/', $object->lines[$i]->desc, $matches)) {
							$mo = new Mo($this->db);
							if ($mo->fetch(0, $matches[1]) > 0 && $mo->fk_bom > 0) {
								$sql = "SELECT p.rowid, p.ref, p.label, b.qty FROM ".MAIN_DB_PREFIX."bom_bomline as b LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = b.fk_product WHERE b.fk_bom = " . (int)$mo->fk_bom;
								$resql = $this->db->query($sql);
								if ($resql) {
									while ($line = $this->db->fetch_object($resql)) { $bom_components[] = $line; }
									$this->db->free($resql);
									$line_height_estimation += 4 + (count($bom_components) * 3) + 2; // Title + lines + padding
								}
							}
						}
					}

					// Check if the estimated height fits on the current page
					if (($curY + $line_height_estimation) > ($this->page_hauteur - $heightforfooter - $heightforinfotot)) {
						// Not enough space, finalize the current page and create a new one
						if ($pageposbefore == $pageposbeforeprintlines) {
							$this->_tableau($pdf, $tab_top, $curY - $tab_top, 0, $outputlangs, 0, 1);
						} else {
							$this->_tableau($pdf, $tab_top_newpage, $curY - $tab_top_newpage, 0, $outputlangs, 1, 1);
						}
						$this->_pagefoot($pdf, $object, $outputlangs);

						$pdf->AddPage();
						if (!empty($tplidx)) $pdf->useTemplate($tplidx);
						$pagenb++;
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs);
						}
						// Draw table header on the new page
						$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 0, 0);
						$nexY = $curY = $tab_top_newpage + $this->tabTitleHeight;
					}
					// --- END: Proactive Page Break Logic ---


					$pdf->SetFont('', '', $default_font_size - 1);
					$pdf->SetTextColor(0, 0, 0);

					// We start a transaction, in case the description is too long and causes an auto page break
					$pdf->startTransaction();

					// Description
					if ($this->getColumnStatus('desc')) {
						$this->printColDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, $hideref, $hidedesc);
					}
					$nexYAfterDesc = $pdf->GetY();

					$pageposafter = $pdf->getPage();

					if ($pageposafter > $pageposbefore) {
						// A page break was triggered by the description. Rollback and let the main loop handle it.
						$pdf->rollbackTransaction(true);

						if ($pageposbefore == $pageposbeforeprintlines) {
							$this->_tableau($pdf, $tab_top, $curY - $tab_top, 0, $outputlangs, 0, 1);
						} else {
							$this->_tableau($pdf, $tab_top_newpage, $curY - $tab_top_newpage, 0, $outputlangs, 1, 1);
						}
						$this->_pagefoot($pdf, $object, $outputlangs);
						$pdf->AddPage();
						if (!empty($tplidx)) $pdf->useTemplate($tplidx);
						$pagenb++;
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs);
						}
						$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 0, 0);
						$curY = $tab_top_newpage + $this->tabTitleHeight;

						// Redraw the description on the new page
						if ($this->getColumnStatus('desc')) {
							$this->printColDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, $hideref, $hidedesc);
						}
						$nexYAfterDesc = $pdf->GetY();
					} else {
						$pdf->commitTransaction();
					}


					// Other columns
					if ($this->getColumnStatus('position')) $this->printStdColumnContent($pdf, $curY, 'position', (string) ($i + 1));
					if ($this->getColumnStatus('subprice')) $this->printStdColumnContent($pdf, $curY, 'subprice', price($object->lines[$i]->subprice, 0, $outputlangs));
					if ($this->getColumnStatus('qty_asked')) $this->printStdColumnContent($pdf, $curY, 'qty_asked', $object->lines[$i]->qty_asked);
					if ($this->getColumnStatus('garantie')) $this->printStdColumnContent($pdf, $curY, 'garantie', $this->getLineGarantie($object, $i));
					if ($this->getColumnStatus('unit_order')) $this->printStdColumnContent($pdf, $curY, 'unit_order', measuringUnitString($object->lines[$i]->fk_unit));

					if ($this->getColumnStatus('linetotal')) {
						$line_total = $object->lines[$i]->subprice * $object->lines[$i]->qty_asked;
						$grand_total_ht += $line_total;
						$this->printStdColumnContent($pdf, $curY, 'linetotal', price($line_total, 0, $outputlangs));
					}

					$nexY = max($nexYAfterDesc, $pdf->GetY());

					// Print BOM components
					if (!empty($bom_components)) {
						$current_Y_for_components = $nexY + 1;
						$pdf->SetFont('', 'B', $default_font_size - 1);
						$pdf->SetXY($this->marge_gauche + 2, $current_Y_for_components);
						$pdf->MultiCell(0, 4, $outputlangs->transnoentities("- Composants :"), 0, 'L');
						$current_Y_for_components += 4;
						$pdf->SetFont('', '', $default_font_size - 2);

						foreach ($bom_components as $component) {
							$component_label = "- " . $component->ref . " - " . dol_trunc($component->label, 45) . " (x" . (int)$component->qty . ")";
							$pdf->SetXY($this->marge_gauche + 5, $current_Y_for_components);
							$pdf->MultiCell(0, 3, $outputlangs->convToOutputCharset($component_label), 0, 'L');
							$current_Y_for_components += 3;
						}
						$nexY = $current_Y_for_components + 1;
					}

					// Draw line separator
					if (getDolGlobalString('MAIN_PDF_DASH_BETWEEN_LINES') && $i < ($nblines - 1)) {
						$pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(80, 80, 80)));
						$pdf->line($this->marge_gauche, $nexY, $this->page_largeur - $this->marge_droite, $nexY);
					}
					$nexY += 0.5; // Small padding
				}

				// --- End of loop ---

				// Close the table frame on the final page.
				if ($pagenb == 1) {
					$this->_tableau($pdf, $tab_top, $nexY - $tab_top, 0, $outputlangs, 0, 1);
					$bottomlasttab = $nexY;
				} else {
					$this->_tableau($pdf, $tab_top_newpage, $nexY - $tab_top_newpage, 0, $outputlangs, 1, 1);
					$bottomlasttab = $nexY;
				}


				// Add the totals row
				$nexY = $this->_tableau_tot($pdf, $object, $grand_total_ht, $bottomlasttab, $outputlangs);


				// --- START: Smart Warranty Placement to avoid extra blank pages ---
				$warranty_conditions = "شروط الضمان:\n";
				$warranty_conditions .= "1- تضمن الشركة للزبون العتاد المباع ضده كل عيوب التصنيع والعمالة ضمن المدة المحددة ابتداء من تاريخ الشراء.\n";
				$warranty_conditions .= "2- نظام التشغيل والبرامج + نضائد الكمبيوتر المحمول ولوحات المفاتيح ومقود اللعب الفأرة مكبرات الصوت والفلاش ديسك والمستهلكات مضمونة فقط عند أول تشغيل.\n";
				$warranty_conditions .= "3- تثبيت البرمجيات غير مضمون.\n";
				$warranty_conditions .= "4- لا تضمن الشركة أن هذا العتاد سيشتغل بصفة غير منقطعة أو دون أخطاء.\n";
				$warranty_conditions .= "5- الضمان لا يشمل إرجاع المنتوج أو استبداله؛ تمنح الشركة 3 أيام من تاريخ الاستلام للإرجاع مع رسوم 5% من سعر المنتج (باستثناء مصاريف التوصيل).\n";
				$warranty_conditions .= "6- يجب على الزبون الحفاظ على التغليف الأصلي طوال مدة الضمان.\n";
				$warranty_conditions .= "7- لا يشمل الضمان كسر السرعة (overclocking)، الصيانة السيئة، التعديلات غير المصرح بها، بطاقات التوسعة غير المعتمدة، أو النقل غير السليم.\n";
				$warranty_conditions .= "8- ضمان الطابعة يقتصر على تشغيلها فقط، ولا يشمل أخطاء الطباعة أو سوء تعبئة الخزان.";

				$pdf->SetFont('aealarabiya', '', pdf_getPDFFontSize($outputlangs) - 1);
				$width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
				$warranty_height = $pdf->getStringHeight($width, $outputlangs->convToOutputCharset($warranty_conditions));

				$page_limit = $this->page_hauteur - $heightforfooter - 5;
				if ($pdf->GetY() + $warranty_height > $page_limit) {
					$this->_pagefoot($pdf, $object, $outputlangs);
					$pdf->AddPage();
					$pagenb++;
					$pdf->SetXY($this->marge_gauche, $this->marge_haute);
				} else {
					$pdf->Ln(3);
				}
				$pdf->SetTextColor(0, 0, 0);
				$pdf->MultiCell($width, 4, $outputlangs->convToOutputCharset($warranty_conditions), 0, 'R');
				// --- END: Smart Warranty Placement ---

				// Add final page footer
				$this->_pagefoot($pdf, $object, $outputlangs);

				$pdf->Close();
				$pdf->Output($file, 'F');

				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
				$reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);
				if ($reshook < 0) { $this->error = $hookmanager->error; $this->errors = $hookmanager->errors; }
				dolChmod($file);
				$this->result = array('fullpath' => $file);
				return 1;
			} else {
				$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
				return 0;
			}
		} else {
			$this->error = $langs->transnoentities("ErrorConstantNotDefined", "EXP_OUTPUTDIR");
			return 0;
		}
	}

	protected function _tableau_tot(&$pdf, $object, $total_ht, $posy, $outputlangs)
	{
		$curY = $posy + 1;

		$col_width = $this->cols['linetotal']['width'];
		$col_pos_x = $this->getColumnContentXStart('linetotal');

		$pdf->SetFont('', 'B', pdf_getPDFFontSize($outputlangs) - 1);

		// Label "Total"
		$label = $outputlangs->transnoentities("");
		$label_width = $pdf->GetStringWidth($label) + 2;
		$label_pos_x = $col_pos_x - $label_width;
		$pdf->SetXY($label_pos_x, $curY);
		$pdf->MultiCell($label_width, 4, $label, 0, 'R');

		// Total Amount
		$pdf->SetXY($col_pos_x, $curY);
		$pdf->MultiCell($col_width, 4, price($total_ht, 0, $outputlangs), 0, 'R');

		return $pdf->GetY();
	}

	/**
	 *   Show table for lines (draws the grid and titles)
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		float|int	$tab_top		Top position of table
	 *   @param		float|int	$tab_height		Height of table (rectangle)
	 *   @param		float		$nexY			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		-1 to hide top bar of array
	 *   @param		int			$hidebottom		1 to hide bottom bar of array
	 *   @return	void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0)
	{
		// hidetop=0 (first page) or 1 (following pages), hidebottom=0 (not last line) or 1 (last line)
		if ($hidetop) $hidetop = -1; // TCPDF wants -1 to hide top
		if ($hidebottom) $hidebottom = 1;

		if (empty($hidetop) && getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
			$pdf->RoundedRect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, $this->corner_radius, '1001', 'F', array(), explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
		}
		$pdf->SetDrawColor(128, 128, 128);
		$this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, $hidetop, $hidebottom, 'D');

		// Draw titles if not hidden
		if (empty($hidetop)) {
			$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);
			$pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight);
		}
	}


	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf;
		$ltrdirection = ($outputlangs->trans("DIRECTION") == 'rtl') ? 'R' : 'L';
		$outputlangs->load("orders");
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 110;
		$posy = $this->marge_haute;
		$page_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

		if ($this->emetteur->logo) {
			$logo = $conf->mycompany->dir_output.'/logos/'.(!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? 'thumbs/'.$this->emetteur->logo_small : $this->emetteur->logo);
			if (is_readable($logo)) {
				$height = pdf_getHeightForLogo($logo);
				$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height);
			}
		} else {
			$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
		}

		// Right-hand side info box
		$pdf->SetDrawColor(128, 128, 128);
		$posx = $this->page_largeur - $w - $this->marge_droite;
		$posy_right = $this->marge_haute;

		// Title
		$pdf->SetFont('', 'B', $default_font_size + 2);
		$pdf->SetXY($posx, $posy_right);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->MultiCell($w, 4, $outputlangs->transnoentities("SendingSheet"), '', 'R');
		$posy_right += 5;

		// Reset font for details
		$pdf->SetFont('', '', $default_font_size);

		// Shipment Ref
		$pdf->SetXY($posx, $posy_right);
		$pdf->MultiCell($w, 4, $outputlangs->transnoentities("RefSending")." : ".$outputlangs->convToOutputCharset($object->ref), '', 'R');
		$posy_right += 4;

		// Delivery Date
		if (!empty($object->date_delivery)) {
			$pdf->SetXY($posx, $posy_right);
			$pdf->MultiCell($w, 4, $outputlangs->transnoentities("Date")." : ".dol_print_date($object->date_delivery, 'daytext', false, $outputlangs), '', 'R');
			$posy_right += 4;
		}

		// Customer Code
		if (!empty($object->thirdparty->code_client)) {
			$pdf->SetXY($posx, $posy_right);
			$pdf->MultiCell($w, 4, $outputlangs->transnoentities("CustomerCode")." : ".$outputlangs->convToOutputCharset($object->thirdparty->code_client), '', 'R');
			$posy_right += 4;
		}

		// Sales Order Ref
		if ($object->origin == 'commande' && !empty($object->origin_id)) {
			$order = new Commande($this->db);
			if ($order->fetch($object->origin_id) > 0) {
				$pdf->SetXY($posx, $posy_right);
				$pdf->MultiCell($w, 4, $outputlangs->transnoentities("RefOrder")." : ".$outputlangs->convToOutputCharset($order->ref), '', 'R');
				$posy_right += 4;
			}
		}
		
// Creation Date
if (!empty($object->date_creation)) {
    $pdf->SetXY($posx, $posy_right);
    $pdf->MultiCell($w, 4, $outputlangs->transnoentities("DateCreation")." : ".dol_print_date($object->date_creation, 'daytext', false, $outputlangs), '', 'R');
    $posy_right += 4;
}
		$top_shift = 15;
		$posy_header = $posy_right + 5; // Position below the info box
		$pdf->SetFillColor(230, 230, 230);
		$pdf->Rect($this->marge_gauche, $posy_header, $page_width, 10, 'F');
		$pdf->SetFont('aealarabiya', '', $default_font_size + 2);
		$pdf->SetXY($this->marge_gauche, $posy_header + 2);
		$pdf->MultiCell($page_width, 5, $outputlangs->convToOutputCharset('CERTIFICAT DE GARANTIE                     الضمان شهادة'), 0, 'C');

		if ($showaddress) {
			$posy_address = $posy_header + 15; // Position below the warranty banner
			$widthrecbox = 82;
			$hautcadre = 35; // Slightly reduced height to fit

			// Sender
			$posx_sender = $this->marge_gauche;
			$carac_emetteur = pdf_build_address($outputlangs, $this->emetteur);
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($posx_sender, $posy_address - 5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("Sender"), 0, $ltrdirection);
			$pdf->SetXY($posx_sender, $posy_address);
			$pdf->SetFillColor(230, 230, 230);
			$pdf->RoundedRect($posx_sender, $posy_address, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'F');
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetXY($posx_sender + 2, $posy_address + 3);
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->MultiCell($widthrecbox - 4, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$current_y_sender = $pdf->GetY();
			$pdf->SetXY($posx_sender + 2, $current_y_sender);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->MultiCell($widthrecbox - 4, 4, $carac_emetteur, 0, $ltrdirection);

			// Recipient
			$posx_recipient = $this->page_largeur - $this->marge_droite - $widthrecbox;
			$carac_client_name = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
			$carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, null, 0, 'targetwithdetails', $object);
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($posx_recipient, $posy_address - 5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("Recipient"), 0, $ltrdirection);
			$pdf->RoundedRect($posx_recipient, $posy_address, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'D');
			$pdf->SetXY($posx_recipient + 2, $posy_address + 3);
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->MultiCell($widthrecbox - 4, 4, $carac_client_name, 0, $ltrdirection);
			$current_y_recipient = $pdf->GetY();
			$pdf->SetXY($posx_recipient + 2, $current_y_recipient);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->MultiCell($widthrecbox - 4, 4, $carac_client, 0, $ltrdirection);
		}
		$pdf->SetTextColor(0, 0, 0);
		return $top_shift;
	}

	/**
	 * Show footer of page.
	 *
	 * @param   TCPDF   &$pdf             PDF object
	 * @param   Object  $object           Object to show
	 * @param   Translate   $outputlangs    Output langs
	 * @return  void
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs)
	{
		// Add watermark if necessary
		if (! empty($this->watermark)) {
			pdf_watermark($pdf, $outputlangs, $this->page_hauteur, $this->page_largeur, $this->watermark);
		}

		// Page number at bottom center of every page
		$pdf->SetY(-15); // Position 15mm from the bottom
		$pdf->SetFont('', '', pdf_getPDFFontSize($outputlangs) - 2);
		$pdf->SetTextColor(0, 0, 0);
		if (method_exists($pdf, 'getAliasNbPages')) {
			$page_string = $outputlangs->transnoentities("Page") . ' ' . $pdf->getPage() . ' / ' . $pdf->getAliasNbPages();
		} else {
			$page_string = $outputlangs->transnoentities("Page") . ' ' . $pdf->getPage();
		}
		$pdf->Cell(0, 10, $page_string, 0, 0, 'C');
	}

	protected function getLineGarantie($object, $i)
	{
		$garantieMap = ['1' => '0 MOIS -3 jours-', '2' => '1 MOIS', '3' => '3 MOIS', '4' => '6 MOIS', '5' => '12 MOIS'];
		if (!empty($object->lines[$i]->fk_product)) {
			$sql = "SELECT garantie FROM ".MAIN_DB_PREFIX."product_extrafields WHERE fk_object = ".((int) $object->lines[$i]->fk_product);
			$resql = $this->db->query($sql);
			if ($resql) {
				$obj = $this->db->fetch_object($resql);
				$this->db->free($resql); // Free resource
				if ($obj && !empty($obj->garantie)) {
					return isset($garantieMap[$obj->garantie]) ? $garantieMap[$obj->garantie] : '';
				}
			}
		}
		return '';
	}

	protected function getLineSerialNumber($object, $i)
	{
		$serials = array();
		if (empty($object->lines[$i]->id)) return '';
		$line_id = (int)$object->lines[$i]->id;
		$sql = "SELECT batch FROM ".MAIN_DB_PREFIX."expeditiondet_batch WHERE fk_expeditiondet = ".$line_id;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!empty($obj->batch)) {
					$serials[] = $obj->batch;
				}
			}
			$this->db->free($resql);
		}
		return implode(', ', $serials);
	}

	/**
	 * Build the description string for a given line (for height calculation purposes).
	 *
	 * @param   object      $object         The main object
	 * @param   int         $i              The line index
	 * @param   Translate   $outputlangs    The language object
	 * @param   int         $hideref        Hide reference flag
	 * @param   int         $hidedesc       Hide description flag
	 * @return  string                      The constructed description string
	 */
	protected function getColDescContent($object, $i, $outputlangs, $hideref, $hidedesc)
	{
		$line = $object->lines[$i];
		$description_text = '';

		// Product Ref
		if (empty($hideref) && !empty($line->product_ref)) {
			$description_text .= '<b>' . $line->product_ref . '</b>';
		}
		// Product Label
		if (!empty($line->product_label)) {
			if (!empty($description_text)) {
				$description_text .= ' - ';
			}
			$description_text .= $line->product_label;
		}
		// Line Description
		if (empty($hidedesc) && !empty($line->description)) {
			if (!empty($description_text)) {
				$description_text .= "\n";
			}
			$description_text .= $line->description;
		}
		// Serial Numbers
		$serial_numbers = $this->getLineSerialNumber($object, $i);
		if (!empty($serial_numbers)) {
			if (!empty($description_text)) {
				$description_text .= "\n";
			}
			$description_text .= '<b>'.$outputlangs->transnoentities('SerialNumber').'</b>: '.$serial_numbers;
		}

		return $outputlangs->convToOutputCharset($description_text);
	}

	public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $hookmanager;

		$this->defaultContentsFieldsStyle = array('align' => 'R', 'padding' => array(1, 0.5, 1, 0.5));
		$this->defaultTitlesFieldsStyle = array('align' => 'C', 'padding' => array(0.5, 0, 0.5, 0));

		$rank = 0;
		$this->cols['position'] = array('rank' => $rank, 'width' => 7, 'status' => true, 'title' => array('textkey' => '#', 'align' => 'C'), 'content' => array('align' => 'C'));
		$rank += 10;
		$this->cols['desc'] = array('rank' => $rank, 'width' => false, 'status' => true, 'title' => array('textkey' => 'Designation', 'align' => 'L'), 'content' => array('align' => 'L'));
		$rank += 10;
		$this->cols['photo'] = array('rank' => $rank, 'width' => getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH', 15), 'status' => $this->atleastonephoto, 'title' => array('textkey' => 'Photo', 'label' => ' '), 'border-left' => false);
		$rank += 10;
		$this->cols['subprice'] = array('rank' => $rank, 'width' => 20, 'status' => (bool) getDolGlobalString('SHIPPING_PDF_DISPLAY_AMOUNT_HT'), 'title' => array('textkey' => 'PriceUHT'), 'border-left' => true);
		$rank += 10;
		$this->cols['qty_asked'] = array('rank' => $rank, 'width' => 12, 'status' => !(bool) getDolGlobalString('SHIPPING_PDF_HIDE_ORDERED'), 'title' => array('textkey' => 'Qty'), 'border-left' => true, 'content' => array('align' => 'C'));
		$rank += 10;
		$this->cols['garantie'] = array('rank' => $rank, 'width' => 15, 'status' => true, 'title' => array('textkey' => 'Warranty'), 'border-left' => true, 'content' => array('align' => 'C'));
		$rank += 10;
		$this->cols['unit_order'] = array('rank' => $rank, 'width' => 8, 'status' => (bool) getDolGlobalString('PRODUCT_USE_UNITS'), 'title' => array('textkey' => 'Unit'), 'border-left' => true, 'content' => array('align' => 'C'));
		$rank += 10;
		$this->cols['linetotal'] = array('rank' => $rank, 'width' => 22, 'status' => true, 'title' => array('textkey' => 'Total', 'align' => 'C'), 'content' => array('align' => 'R'), 'border-left' => true);


		$parameters = array('object' => $object, 'outputlangs' => $outputlangs, 'hidedetails' => $hidedetails, 'hidedesc' => $hidedesc, 'hideref' => $hideref);
		$reshook = $hookmanager->executeHooks('defineColumnField', $parameters, $this);
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		} elseif (empty($reshook)) {
			$this->cols = array_replace($this->cols, $hookmanager->resArray);
		} else {
			$this->cols = $hookmanager->resArray;
		}
	}
}
