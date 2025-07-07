<?php
/* Copyright (C) 2004-2014  Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin				<regis.houssin@inodbox.com>
 * Copyright (C) 2008		Raphael Bertrand			<raphael.bertrand@resultic.fr>
 * Copyright (C) 2010-2013	Juanjo Menent				<jmenent@2byte.es>
 * Copyright (C) 2012      	Christophe Battarel			<christophe.battarel@altairis.fr>
 * Copyright (C) 2012       Cedric Salvador				<csalvador@gpcsolutions.fr>
 * Copyright (C) 2015       Marcos GarcÃƒÂ­a				<marcosgdf@gmail.com>
 * Copyright (C) 2017       Ferran Marcet				<fmarcet@2byte.es>
 * Copyright (C) 2021-2024	Anthony Berton				<anthony.berton@bb2a.fr>
 * Copyright (C) 2018-2024  FrÃƒÂ©dÃƒÂ©ric France				<frederic.france@free.fr>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024	    Nick Fragoulis
 * Copyright (C) 2024       Joachim Kueter				<git-jk@bloxera.com>
 * Copyright (C) 2024		Alexandre Spangaro			<alexandre@inovea-conseil.com>
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *	\file       htdocs/core/modules/commande/doc/pdf_eratosthene.modules.php
 *	\ingroup    order
 *	\brief      File of Class to generate PDF orders with template Eratosthene
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php'; // Added for MO

require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/modules_facture.php';


/**
 *	Class to generate PDF orders with template Eratosthene
 */
class pdf_bon_garantie extends ModelePDFFactures
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var int The environment ID when using a multicompany module
	 */
	public $entity;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string model description (short text)
	 */
	public $description;

	/**
	 * @var int 	Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;

	/**
	 * @var string document type
	 */
	public $type;

	/**
	 * Dolibarr version of the loaded document
	 * @var string Version, possible values are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'''|'development'|'dolibarr'|'experimental'
	 */
	public $version = 'dolibarr';

	/**
	 * @var array<string,array{rank:int,width:float|int,status:bool,title:array{textkey:string,label:string,align:string,padding:array{0:float,1:float,2:float,3:float}},content:array{align:string,padding:array{0:float,1:float,2:float,3:float}}}>	Array of document table columns
	 */
	public $cols;


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf, $langs, $mysoc;

		// Translations
		$langs->loadLangs(array("main", "bills", "products"));

		$this->db = $db;
		$this->name = "bon_garantie";
		$this->description = $langs->trans('PDFEratostheneDescription');
		$this->update_main_doc_field = 1; // Save the name of generated file as the main doc when generating a doc with this template

		// Dimension page
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
		//$this->option_logo = 0; // Display logo
		$this->option_tva = 1; // Manage the vat option FACTURE_TVAOPTION
		$this->option_modereg = 1; // Display payment mode
		$this->option_condreg = 1; // Display payment terms
		$this->option_multilang = 1; // Available in several languages
		$this->option_escompte = 0; // Displays if there has been a discount
		$this->option_credit_note = 0; // Support credit notes
		$this->option_freetext = 1; // Support add of a personalised text
		$this->option_draft_watermark = 1; // Support add of a watermark on drafts
		$this->watermark = '';

		if ($mysoc === null) {
			dol_syslog(get_class($this).'::__construct() Global $mysoc should not be null.'. getCallerInfoString(), LOG_ERR);
			return;
		}

		// Get source company
		$this->emetteur = $mysoc;
		if (empty($this->emetteur->country_code)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2); // By default, if was not defined
		}

		// Define position of columns
		$this->posxdesc = $this->marge_gauche + 1; // used for notes and other stuff


		$this->tabTitleHeight = 5; // default height

		//  Use new system for position of columns, view  $this->defineColumnField()

		$this->tva = array();
		$this->tva_array = array();
		$this->localtax1 = array();
		$this->localtax2 = array();
		$this->atleastoneratenotnull = 0;
		$this->atleastonediscount = 0;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Function to build pdf onto disk
	 *
	 *  @param		Commande	$object				Object to generate
	 *  @param		Translate	$outputlangs		Lang output object
	 *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
	 *  @param		int<0,1>	$hidedetails		Do not show line details
	 *  @param		int<0,1>	$hidedesc			Do not show desc
	 *  @param		int<0,1>	$hideref			Do not show ref
	 *  @return		int<-1,1>						1 if OK, <=0 if KO
	 */
	
	
	
	
	/**
 * Function to build PDF onto disk
 *
 * @param Facture $object Object to generate
 * @param Translate $outputlangs Lang output object
 * @param string $srctemplatepath Full path of source filename for generator using a template file
 * @param int $hidedetails Do not show line details
 * @param int $hidedesc Do not show desc
 * @param int $hideref Do not show ref
 * @return int 1 if OK, <=0 if KO
 */
public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
{
    global $user, $langs, $conf, $mysoc, $db, $hookmanager, $nblines;

    dol_syslog("write_file outputlangs->defaultlang=" . (is_object($outputlangs) ? $outputlangs->defaultlang : 'null'));

    if (!is_object($outputlangs)) {
        $outputlangs = $langs;
    }
    // For backward compatibility with FPDF
    if (getDolGlobalInt('MAIN_USE_FPDF')) {
        $outputlangs->charset_output = 'ISO-8859-1';
    }

    // Load translation files
    $outputlangs->loadLangs(array("main", "dict", "companies", "bills", "products", "compta"));

    // Show Draft Watermark
    if ($object->statut == $object::STATUS_DRAFT && getDolGlobalString('FACTURE_DRAFT_WATERMARK')) {
        $this->watermark = getDolGlobalString('FACTURE_DRAFT_WATERMARK');
    }

    global $outputlangsbis;
    $outputlangsbis = null;
    if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
        $outputlangsbis = new Translate('', $conf);
        $outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
        $outputlangsbis->loadLangs(array("main", "dict", "companies", "bills", "products", "compta"));
    }

    $nblines = (is_array($object->lines) ? count($object->lines) : 0);

    $hidetop = getDolGlobalInt('MAIN_PDF_DISABLE_COL_HEAD_TITLE');

    // Loop to detect if there is at least one image
    $realpatharray = array();
    $this->atleastonephoto = false;
    if (getDolGlobalInt('MAIN_GENERATE_INVOICES_WITH_PICTURE')) {
        $objphoto = new Product($this->db);

        for ($i = 0; $i < $nblines; $i++) {
            if (empty($object->lines[$i]->fk_product)) {
                continue;
            }

            $pdir = array();
            $objphoto->fetch($object->lines[$i]->fk_product);
            if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
                $pdir[0] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product') . $objphoto->id . "/photos/";
                $pdir[1] = get_exdir(0, 0, 0, 0, $objphoto, 'product') . dol_sanitizeFileName($objphoto->ref) . '/';
            } else {
                $pdir[0] = get_exdir(0, 0, 0, 0, $objphoto, 'product');
                $pdir[1] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product') . $objphoto->id . "/photos/";
            }

            $arephoto = false;
            foreach ($pdir as $midir) {
                if (!$arephoto) {
                    if ($conf->entity != $objphoto->entity) {
                        $dir = $conf->product->multidir_output[$objphoto->entity] . '/' . $midir;
                    } else {
                        $dir = $conf->product->dir_output . '/' . $midir;
                    }

                    foreach ($objphoto->liste_photos($dir, 1) as $key => $obj) {
                        if (!getDolGlobalInt('CAT_HIGH_QUALITY_IMAGES')) {
                            $filename = $obj['photo_vignette'] ?: $obj['photo'];
                        } else {
                            $filename = $obj['photo'];
                        }

                        $realpath = $dir . $filename;
                        $arephoto = true;
                        $this->atleastonephoto = true;
                    }
                }
            }

            if ($realpath && $arephoto) {
                $realpatharray[$i] = $realpath;
            }
        }
    }

    if (getMultidirOutput($object)) {
        $object->fetch_thirdparty();

        $deja_regle = $object->getSommePaiement();

        // Definition of $dir and $file
        if ($object->specimen) {
            $dir = getMultidirOutput($object);
            $file = $dir . "/SPECIMEN_garantie.pdf"; // Unique filename for specimen
        } else {
            $objectref = dol_sanitizeFileName($object->ref);
            $dir = getMultidirOutput($object) . "/" . $objectref;
            // Modified filename to include '_garantie' suffix
            $file = $dir . "/" . $objectref . "_garantie.pdf";
        }

        if (!file_exists($dir)) {
            if (dol_mkdir($dir) < 0) {
                $this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
                return 0;
            }
        }

        if (file_exists($dir)) {
            // Add pdfgeneration hook
            if (!is_object($hookmanager)) {
                include_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
                $hookmanager = new HookManager($this->db);
            }
            $hookmanager->initHooks(array('pdfgeneration'));
            $parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
            global $action;
            $reshook = $hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);

            // Set nblines with the new lines content
            $nblines = (is_array($object->lines) ? count($object->lines) : 0);

            // Create pdf instance
            $pdf = pdf_getInstance($this->format);
            $default_font_size = pdf_getPDFFontSize($outputlangs);
            $pdf->SetAutoPageBreak(1, 0);

            $heightforinfotot = 40; // Height for info and total part
            $heightforfreetext = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5);
            $heightforfooter = $this->marge_basse + 8;
            if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS')) {
                $heightforfooter += 6;
            }

            if (class_exists('TCPDF')) {
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
            }
            $pdf->SetFont(pdf_getPDFFont($outputlangs));

            // Set background PDF
            if (getDolGlobalString('MAIN_ADD_PDF_BACKGROUND')) {
                $logodir = $conf->mycompany->dir_output;
                if (!empty($conf->mycompany->multidir_output[$object->entity])) {
                    $logodir = $conf->mycompany->multidir_output[$object->entity];
                }
                $pagecount = $pdf->setSourceFile($logodir . '/' . getDolGlobalString('MAIN_ADD_PDF_BACKGROUND'));
                $tplidx = $pdf->importPage(1);
            }

            $pdf->Open();
            $pagenb = 0;
            $pdf->SetDrawColor(128, 128, 128);

            $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
            $pdf->SetSubject($outputlangs->transnoentities("PdfInvoiceTitle"));
            $pdf->SetCreator("Dolibarr " . DOL_VERSION);
            $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
            $pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref) . " " . $outputlangs->transnoentities("PdfInvoiceTitle") . " " . $outputlangs->convToOutputCharset($object->thirdparty->name));
            if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
                $pdf->SetCompression(false);
            }

            $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);

            // Check for discounts
            for ($i = 0; $i < $nblines; $i++) {
                if ($object->lines[$i]->remise_percent) {
                    $this->atleastonediscount++;
                }
            }

            // New page
            $pdf->AddPage();
            if (!empty($tplidx)) {
                $pdf->useTemplate($tplidx);
            }
            $pagenb++;
            $pagehead = $this->_pagehead($pdf, $object, 1, $outputlangs, $outputlangsbis);
            $top_shift = $pagehead['top_shift'];
            $shipp_shift = $pagehead['shipp_shift'];
            $pdf->SetFont('', '', $default_font_size - 1);
            $pdf->MultiCell(0, 3, '');
            $pdf->SetTextColor(0, 0, 0);

$tab_top = max($this->marge_haute + 90, $pagehead['top_shift'] + $pagehead['shipp_shift']) + 30; // ajouter 30 mm d'espace
            $tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 42 + $top_shift : 10);
            if (!$hidetop && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
                $tab_top_newpage += $this->tabTitleHeight;
            }

            $tab_height = $this->page_hauteur - $tab_top - $heightforfooter - $heightforfreetext;

            $nexY = $tab_top - 1;

            // Incoterm
            $height_incoterms = 0;
            if (isModEnabled('incoterm')) {
                $desc_incoterms = $object->getIncotermsForPDF();
                if ($desc_incoterms) {
                    $tab_top -= 2;
                    $pdf->SetFont('', '', $default_font_size - 1);
                    $pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top - 1, dol_htmlentitiesbr($desc_incoterms), 0, 1);
                    $nexY = max($pdf->GetY(), $nexY);
                    $height_incoterms = $nexY - $tab_top;

                    $pdf->SetDrawColor(192, 192, 192);
                    $pdf->RoundedRect($this->marge_gauche, $tab_top - 1, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $height_incoterms + 3, $this->corner_radius, '1234', 'D');
                    $tab_top = $nexY + 6;
                }
            }

            // Display notes
            $notetoshow = empty($object->note_public) ? '' : $object->note_public;
            if (getDolGlobalString('MAIN_ADD_SALE_REP_SIGNATURE_IN_NOTE')) {
                if (is_object($object->thirdparty)) {
                    $salereparray = $object->thirdparty->getSalesRepresentatives($user);
                    $salerepobj = new User($this->db);
                    $salerepobj->fetch($salereparray[0]['id']);
                    if (!empty($salerepobj->signature)) {
                        $notetoshow = dol_concatdesc($notetoshow, $salerepobj->signature);
                    }
                }
            }
            $extranote = $this->getExtrafieldsInHtml($object, $outputlangs);
            if (!empty($extranote)) {
                $notetoshow = dol_concatdesc($notetoshow, $extranote);
            }

            $pagenb = $pdf->getPage();
            if ($notetoshow) {
                $tab_top -= 2;
                $tab_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
                $pageposbeforenote = $pagenb;

                $substitutionarray = pdf_getSubstitutionArray($outputlangs, null, $object);
                complete_substitutions_array($substitutionarray, $outputlangs, $object);
                $notetoshow = make_substitutions($notetoshow, $substitutionarray, $outputlangs);
                $notetoshow = convertBackOfficeMediasLinksToPublicLinks($notetoshow);








                $pdf->startTransaction();
                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top, dol_htmlentitiesbr($notetoshow), 0, 1);
                $pageposafternote = $pdf->getPage();
                $posyafter = $pdf->GetY();

                if ($pageposafternote > $pageposbeforenote) {
                    $pdf->rollbackTransaction(true);
                    while ($pagenb < $pageposafternote) {
                        $pdf->AddPage();
                        $pagenb++;
                        if (!empty($tplidx)) {
                            $pdf->useTemplate($tplidx);
                        }
                        if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
                            $this->_pagehead($pdf, $object, 0, $outputlangs);
                        }
                        $pdf->setTopMargin($tab_top_newpage);
                        $pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext);
                    }

                    $pdf->setPage($pageposbeforenote);
                    $pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext);
                    $pdf->SetFont('', '', $default_font_size - 1);
                    $pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top, dol_htmlentitiesbr($notetoshow), 0, 1);
                    $pageposafternote = $pdf->getPage();
                    $posyafter = $pdf->GetY();

                    if ($posyafter > ($this->page_hauteur - ($heightforfooter + $heightforfreetext + 20))) {
                        $pdf->AddPage('', '', true);
                        $pagenb++;
                        $pageposafternote++;
                        $pdf->setPage($pageposafternote);
                        $pdf->setTopMargin($tab_top_newpage);
                        $pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext);
                    }

                    $i = $pageposbeforenote;
                    while ($i < $pageposafternote) {
                        $pdf->setPage($i);
                        $pdf->SetDrawColor(128, 128, 128);
                        if ($i > $pageposbeforenote) {
                            $height_note = $this->page_hauteur - ($tab_top_newpage + $heightforfooter);
                            $pdf->RoundedRect($this->marge_gauche, $tab_top_newpage - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');
                        } else {
                            $height_note = $this->page_hauteur - ($tab_top + $heightforfooter);
                            $pdf->RoundedRect($this->marge_gauche, $tab_top - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');
                        }
                        $pdf->setPageOrientation('', 1, 0);
                        $this->_pagefoot($pdf, $object, $outputlangs, 1);
                        $i++;
                    }

                    $pdf->setPage($pageposafternote);
                    if (!empty($tplidx)) {
                        $pdf->useTemplate($tplidx);
                    }
                    if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
                        $this->_pagehead($pdf, $object, 0, $outputlangs);
                    }
                    $height_note = $posyafter - $tab_top_newpage;
                    $pdf->RoundedRect($this->marge_gauche, $tab_top_newpage - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');
                } else {
                    $pdf->commitTransaction();
                    $posyafter = $pdf->GetY();
                    $height_note = $posyafter - $tab_top;
                    $pdf->RoundedRect($this->marge_gauche, $tab_top - 1, $tab_width, $height_note + 1, $this->corner_radius, '1234', 'D');

                    if ($posyafter > ($this->page_hauteur - ($heightforfooter + $heightforfreetext + 20))) {
                        $pdf->AddPage('', '', true);
                        $pagenb++;
                        $pageposafternote++;
                        $pdf->setPage($pageposafternote);
                        if (!empty($tplidx)) {
                            $pdf->useTemplate($tplidx);
                        }
                        if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
                            $this->_pagehead($pdf, $object, 0, $outputlangs);
                        }
                        $posyafter = $tab_top_newpage;
                    }
                }

                $tab_height -= $height_note;
                $tab_top = $posyafter + 6;
            } else {
                $height_note = 0;
            }

            $this->prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);
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
            $pdf->startTransaction();
            $this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);
            $pdf->rollbackTransaction(true);

            $nexY = $tab_top + $this->tabTitleHeight;

            $pageposbeforeprintlines = $pdf->getPage();
            $pagenb = $pageposbeforeprintlines;
            for ($i = 0; $i < $nblines; $i++) {
                // Ensure product_label is populated if not already on the invoice line object
                if (empty($object->lines[$i]->product_label) && !empty($object->lines[$i]->fk_product)) {
                    $main_line_product = new Product($this->db);
                    if ($main_line_product->fetch($object->lines[$i]->fk_product) > 0) {
                        $object->lines[$i]->product_label = $main_line_product->label; // Store it on the line object for this generation
                    }
                }

                $current_line_mo_ref = ''; // Initialize for each line
                $linePosition = $i + 1;
                $curY = $nexY;

                if (isset($object->lines[$i]->pagebreak) && $object->lines[$i]->pagebreak) {
                    $pdf->AddPage();
                    if (!empty($tplidx)) {
                        $pdf->useTemplate($tplidx);
                    }
                    $pdf->setPage($pdf->getNumPages());
                    $nexY = $tab_top_newpage;
                }

                $this->resetAfterColsLinePositionsData($nexY, $pdf->getPage());

                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->SetTextColor(0, 0, 0);

                // START: MO BOM Component Logic (Replaces previous BOM logic for product ID 483)
                $extracted_mo_ref = null;
                $mo_lines_to_display = array(); // Initialize as empty

                if ($object->lines[$i]->fk_product == 483) {
                    $line_description = $object->lines[$i]->desc; // Or ->libelle if more appropriate
                    // Try to extract MO ref, e.g., "Costum-PC2506-000028" or "Costum-PC2506-000028 (Fabrication)"
                    if (preg_match('/([A-Za-z0-9-]+-?\d+)(?: \(Fabrication\))?/', $line_description, $matches)) {
                        $extracted_mo_ref = $matches[1];
                        $current_line_mo_ref = $extracted_mo_ref; // Store for serial number display
                        dol_syslog("Extracted MO Ref: " . $extracted_mo_ref . " from line " . $i . "; Stored in current_line_mo_ref", LOG_DEBUG);

                        $mo = new Mo($this->db);
                        $result = $mo->fetch(0, $extracted_mo_ref);

                        if ($result > 0) {
                            $target_bom_id = $mo->fk_bom;
                            if (empty($target_bom_id) || $target_bom_id <= 0) {
                                dol_syslog("PDF Bon Garantie: MO (ref: ".$extracted_mo_ref.") does not have a valid fk_bom.", LOG_WARNING);
                                // Decide whether to 'continue' the outer loop for $i, or just skip component processing for this $mo
                                // For now, let's assume we might still want to print the main MO line, so we'll allow component processing to be skipped.
                                // If 'continue 2;' (to skip outer loop iteration) is desired, that's a different behavior.
                                // For now, this will let the $mo_lines_to_display (or new $bom_components_from_bomline) be empty.
                            }

                            if (!empty($mo->lines)) {
                                foreach ($mo->lines as $moline) {
                                    if ($moline->role == 'toconsume') {
                                        $mo_lines_to_display[] = $moline;
                                    }
                                }
                                if (empty($mo_lines_to_display)) {
                                    dol_syslog("MO Ref: " . $extracted_mo_ref . " fetched successfully but has no 'toconsume' lines.", LOG_WARNING);
                                }
                            } else {
                                dol_syslog("MO Ref: " . $extracted_mo_ref . " fetched successfully but has no lines.", LOG_WARNING);
                            }
                        } else {
                            dol_syslog("Failed to fetch MO with ref: " . $extracted_mo_ref . ". Error: " . $mo->error, LOG_ERR);
                        }
                    } else {
                        dol_syslog("Could not extract MO reference from description: '" . $line_description . "' for product line " . $i, LOG_WARNING);
                    }
                }
                // END: MO BOM Component Logic - Data Fetching

                $imglinesize = array();
                if (!empty($realpatharray[$i])) {
                    $imglinesize = pdf_getSizeForImage($realpatharray[$i]);
                }

                $pdf->setTopMargin($tab_top_newpage);
                $pdf->setPageOrientation('', 1, $heightforfooter);
                $pageposbefore = $pdf->getPage();
                $curYBefore = $curY;

                $showpricebeforepagebreak = getDolGlobalInt('MAIN_PDF_DATA_ON_FIRST_PAGE');
                $posYAfterImage = 0;

                if ($this->getColumnStatus('photo')) {
                    $imageTopMargin = 1;
                    if (isset($imglinesize['width']) && isset($imglinesize['height']) && ($curY + $imageTopMargin + $imglinesize['height']) > ($this->page_hauteur - $heightforfooter)) {
                        $pdf->AddPage('', '', true);
                        if (!empty($tplidx)) {
                            $pdf->useTemplate($tplidx);
                        }
                        $pdf->setPage($pageposbefore + 1);
                        $pdf->setPageOrientation('', 1, $heightforfooter);
                        $curY = $tab_top_newpage;
                        $showpricebeforepagebreak = 0;
                    }

                    if (!empty($this->cols['photo']) && isset($imglinesize['width']) && isset($imglinesize['height'])) {
                        $pdf->Image($realpatharray[$i], $this->getColumnContentXStart('photo'), $curY + $imageTopMargin, $imglinesize['width'], $imglinesize['height'], '', '', '', 2, 300);
                        $posYAfterImage = $curY + $imglinesize['height'];
                        $this->setAfterColsLinePositionsData('photo', $posYAfterImage, $pdf->getPage());
                    }
                }

                $pdf->setPageOrientation('', 1, $heightforfooter);

//...
                if ($this->getColumnStatus('desc')) {
//...                    // UNIFIED LOGIC: Use the same method for all products to fix the left alignment issue.
                    // This logic correctly positions the description at the left margin.
                    $desc_content = !empty($object->lines[$i]->product_label) ? $object->lines[$i]->product_label : (!empty($object->lines[$i]->desc) ? $object->lines[$i]->desc : $object->lines[$i]->libelle);

                    $colKey = 'desc';
                    $line_height_main = 4;
                    $x_start = $this->marge_gauche; // Force position to the left margin, which is the correct behavior.
                    $col_width = $this->cols[$colKey]['content']['width'] ?? $this->cols[$colKey]['width'];
                    $align = $this->cols[$colKey]['content']['align'] ?? 'L';
                    $padding = $this->cols[$colKey]['content']['padding'] ?? array(0.5, 0.5, 0.5, 0.5);

                    $pdf->SetXY($x_start + $padding[3], $curY + $padding[0]);
                    $pdf->MultiCell($col_width - $padding[1] - $padding[3], $line_height_main, $outputlangs->convToOutputCharset($desc_content), 0, $align, 0, 1, '', '', true, 0, false, true, $line_height_main, 'M');

                    // Using $pdf->GetY() is robust as it correctly finds the cursor position after text wrapping.
                    $this->setAfterColsLinePositionsData($colKey, $pdf->GetY(), $pdf->getPage());
                }

//
                $afterPosData = $this->getMaxAfterColsLinePositionsData();
                $pdf->setPage($pageposbefore);
                $pdf->setTopMargin($this->marge_haute);
                $pdf->setPageOrientation('', 0, $heightforfooter);

                if ($afterPosData['page'] > $pageposbefore && (empty($showpricebeforepagebreak) || ($curY + 4) > ($this->page_hauteur - $heightforfooter))) {
                    $pdf->setPage($afterPosData['page']);
                    $curY = $tab_top_newpage;
                }

                $pdf->SetFont('', '', $default_font_size - 1);

                if ($this->getColumnStatus('position')) {
                    $this->printStdColumnContent($pdf, $curY, 'position', strval($linePosition));
                }

                if ($this->getColumnStatus('vat')) {
                    $vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'vat', $vat_rate);
                }

                if ($this->getColumnStatus('subprice')) {
                    $up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'subprice', $up_excl_tax);
                }

                if ($this->getColumnStatus('qty')) {
                    $qty = pdf_getlineqty($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'qty', $qty);
                }

                if ($this->getColumnStatus('serialnumber')) {
                    $serialnumber_to_display = ''; // Initialize
                    if ($object->lines[$i]->fk_product == 483 && !empty($current_line_mo_ref)) {
                        $serialnumber_to_display = $current_line_mo_ref;
                    } else {
                        $serialnumber_to_display = $this->getLineSerialNumber($object, $i);
                    }
                    
                    $this->printStdColumnContent($pdf, $curY, 'serialnumber', $serialnumber_to_display);
                }

                if ($this->getColumnStatus('garantie')) {
                    $garantie = $this->getLineGarantie($object, $i);
                    $this->printStdColumnContent($pdf, $curY, 'garantie', $garantie);
                }

                // START: Display BOM Components
                if ($object->lines[$i]->fk_product == 483 && !empty($bom_components)) {
                    $bom_start_y = $pdf->GetY(); // Get Y position after the main line item text has been printed by previous MultiCells
                    // If the description of the main item is very long, it might have pushed Y down significantly.
                    // We need to ensure BOM starts after the *actual* end of the main line item's description.
                    // The `desc` column processing uses writeHTMLCell, its final Y is what we need.
                    // However, other columns are printed *after* desc using $curY.
                    // The $afterPosData from $this->getMaxAfterColsLinePositionsData() before this BOM block
                    // should give the correct nexY to start drawing.
                    // So, we'll use $nexY as the starting point for drawing the BOM title,
                    // ensuring it's positioned after all standard line columns.

                    $nexY_bom_content_start = $nexY + 2; // Initial Y for BOM content, adjusted from $nexY passed into the loop iteration

                    // Check if BOM content will fit or if we need a new page *before* drawing title
                    $estimated_bom_height = 4 + (count($bom_components) * 3) + 2; // Title + lines + padding
                    if (($nexY_bom_content_start + $estimated_bom_height) > ($this->page_hauteur - $heightforfooter - 5)) {
                        $pdf->AddPage();
                        if (!empty($tplidx)) {
                            $pdf->useTemplate($tplidx);
                        }
                        $this->_pagehead($pdf, $object, 0, $outputlangs);
                        $this->pdfTabTitles($pdf, $tab_top_newpage, $tab_height, $outputlangs, $hidetop);
                        $nexY = $tab_top_newpage + $this->tabTitleHeight; // Reset nexY for the new page
                        $nexY_bom_content_start = $nexY + 2; // Reset BOM start Y for new page
                    }

                    // BOM Title
                    $pdf->SetFont('', 'B', $default_font_size - 2);
                    $pdf->SetXY($this->marge_gauche, $nexY_bom_content_start);
                    $pdf->MultiCell(0, 3, $outputlangs->transnoentities("BOMComponentsTitle", "Composants :"), 0, 'L');
                    $nexY_bom_content_start += 4; // Space after title

                    // BOM Component Table Headers (Optional, simple version here)
                    // You could add headers like "Ref", "Description", "Qty" here if desired
                    // For now, keeping it simple as per initial implementation

                    $pdf->SetFont('', '', $default_font_size - 2);
                    $col_ref_width = 30;
                    $col_label_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite - $col_ref_width - 20 - 10; // remaining width for label
                    $col_qty_width = 20;

                    foreach ($bom_components as $component) {
                        // Page break check for each component line
                        if ($nexY_bom_content_start > ($this->page_hauteur - $heightforfooter - 8)) { // Adjusted threshold for component line
                            $pdf->AddPage();
                            if (!empty($tplidx)) {
                                $pdf->useTemplate($tplidx);
                            }
                            $this->_pagehead($pdf, $object, 0, $outputlangs);
                            $this->pdfTabTitles($pdf, $tab_top_newpage, $tab_height, $outputlangs, $hidetop);
                            $nexY = $tab_top_newpage + $this->tabTitleHeight; // Reset nexY for the new page
                            $nexY_bom_content_start = $nexY + 2; // Reset BOM start Y

                            // Optional: Redraw BOM Title if it makes sense on a new page
                            $pdf->SetFont('', 'B', $default_font_size - 2);
                            $pdf->SetXY($this->marge_gauche, $nexY_bom_content_start);
                            $pdf->MultiCell(0, 3, $outputlangs->transnoentities("BOMComponentsTitle", "Composants :"), 0, 'L');
                            $nexY_bom_content_start += 4;
                            $pdf->SetFont('', '', $default_font_size - 2);
                        }

                        $current_x = $this->marge_gauche + 5; // Indent components
                        $pdf->SetXY($current_x, $nexY_bom_content_start);
                        $pdf->MultiCell($col_ref_width, 3, $outputlangs->convToOutputCharset($component['ref']), 0, 'L');

                        $current_x += $col_ref_width;
                        $pdf->SetXY($current_x, $nexY_bom_content_start);
                        $pdf->MultiCell($col_label_width, 3, $outputlangs->convToOutputCharset($component['label']), 0, 'L');

                        $current_x += $col_label_width;
                        $pdf->SetXY($current_x, $nexY_bom_content_start);
                        $pdf->MultiCell($col_qty_width, 3, $outputlangs->convToOutputCharset($component['nb_total']), 0, 'R'); // Align quantity to right

                        $nexY_bom_content_start += 3; // Line height
                    }
                    // Update nexY to reflect space used by BOM
                    $nexY = $nexY_bom_content_start + 2; // Add some padding after BOM
                }
                if ($this->getColumnStatus('unit')) {
                    $unit = pdf_getlineunit($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'unit', $unit);
                }

                if ($this->getColumnStatus('discount') && $object->lines[$i]->remise_percent) {
                    $remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'discount', $remise_percent);
                }

                if ($this->getColumnStatus('totalexcltax')) {
                    $total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'totalexcltax', $total_excl_tax);
                }

                if ($this->getColumnStatus('totalincltax')) {
                    $total_incl_tax = pdf_getlinetotalwithtax($object, $i, $outputlangs, $hidedetails);
                    $this->printStdColumnContent($pdf, $curY, 'totalincltax', $total_incl_tax);
                }

                if (!empty($object->lines[$i]->array_options)) {
                    foreach ($object->lines[$i]->array_options as $extrafieldColKey => $extrafieldValue) {
                        if ($this->getColumnStatus($extrafieldColKey)) {
                            $extrafieldValue = $this->getExtrafieldContent($object->lines[$i], $extrafieldColKey, $outputlangs);
                            $this->printStdColumnContent($pdf, $curY, $extrafieldColKey, $extrafieldValue);
                            $this->setAfterColsLinePositionsData('options_' . $extrafieldColKey, $pdf->GetY(), $pdf->getPage());
                        }
                    }
                }

                // START: Display BOM Components (Moved Location)
                // This section is now after all standard columns for the current line ($i$) have been printed.
                // $bom_components would have been fetched earlier in the loop.
                // We use $this->getMaxAfterColsLinePositionsData() to get the correct Y to start drawing the BOM.
                
                // START: Display MO BOM Components (Refactored to use BOM lines from MO's fk_bom)
                if ($object->lines[$i]->fk_product == 483 && isset($mo) && $mo->id > 0 && !empty($target_bom_id) && $target_bom_id > 0) {
                    $line_height = 4;
                    $current_Y_for_components = $this->getMaxAfterColsLinePositionsData()['y'] + 1;

                    // Title for Components section
                    $pdf->SetFont('', 'B', $default_font_size - 1);
                    $pdf->SetXY($this->marge_gauche, $current_Y_for_components);
                    $pdf->MultiCell(0, $line_height, $outputlangs->transnoentities("- Composants :", "Composants de l'OF " . $extracted_mo_ref . ":"), 0, 'L');
                    $current_Y_for_components += $line_height;
                    $current_Y_for_components += 1; // Extra padding

                    $pdf->SetFont('', '', $default_font_size - 1);

                    $bom_components_from_bomline = array();
                    $sql_get_bom_lines = "SELECT fk_product, qty FROM " . MAIN_DB_PREFIX . "bom_bomline";
                    $sql_get_bom_lines .= " WHERE fk_bom = " . (int)$target_bom_id;
                    $sql_get_bom_lines .= " ORDER BY position ASC"; // Assuming there's a position field

                    $resql_bom_lines = $this->db->query($sql_get_bom_lines);
                    if ($resql_bom_lines) {
                        while ($bom_line_row = $this->db->fetch_object($resql_bom_lines)) {
                            $bom_components_from_bomline[] = $bom_line_row;
                        }
                        $this->db->free($resql_bom_lines);
                    } else {
                        dol_syslog("PDF Bon Garantie: DB error fetching lines for BOM ID: " . $target_bom_id . " - " . $this->db->lasterror(), LOG_ERR);
                    }

                    if (!empty($bom_components_from_bomline)) {
                        foreach ($bom_components_from_bomline as $bom_component_line) {
                            $component_product = new Product($this->db);
                            if ($component_product->fetch($bom_component_line->fk_product) <= 0) {
                                dol_syslog("PDF Bon Garantie: Error fetching component product ID: " . $bom_component_line->fk_product . " for BOM ID: " . $target_bom_id, LOG_WARNING);
                                continue;
                            }

                            if ($current_Y_for_components + $line_height > ($this->page_hauteur - $heightforfooter - 5)) {
                                $pdf->AddPage();
                                if (!empty($tplidx)) $pdf->useTemplate($tplidx);
                                $this->_pagehead($pdf, $object, 0, $outputlangs);
                                $this->pdfTabTitles($pdf, $tab_top_newpage, $tab_height, $outputlangs, $hidetop);
                                $current_Y_for_components = $tab_top_newpage + $this->tabTitleHeight + 1;
                                // Optional: Redraw title for components if it was on a new page
                                $pdf->SetFont('', 'B', $default_font_size - 1);
                                $pdf->SetXY($this->marge_gauche, $current_Y_for_components);
                                $pdf->MultiCell(0, $line_height, $outputlangs->transnoentities("MOBOMComponentsTitle", "Composants de l'OF " . $extracted_mo_ref . ":"), 0, 'L');
                                $current_Y_for_components += $line_height + 1;
                                $pdf->SetFont('', '', $default_font_size - 1);
                            }

                            $component_label_with_qty = "- " . $component_product->label . " (x" . (isset($bom_component_line->qty) ? (int)$bom_component_line->qty : 0) . ")";

                            foreach ($this->cols as $colKey => $colDef) {
                                if (empty($colDef['status'])) continue;

                                $original_col_x_start = $this->getColumnContentXStart($colKey);
                                $original_col_width = $this->cols[$colKey]['content']['width'] ?? $this->cols[$colKey]['width'];
                                $align = $colDef['content']['align'] ?? 'L';
                                $padding_comp = $this->cols[$colKey]['content']['padding'] ?? array(0.5, 0.5, 0.5, 0.5);
                                $current_col_x_start_for_cell = $original_col_x_start;
                                $current_col_width_for_cell = $original_col_width;
                                $content_to_print_for_column = '';

                                if ($colKey == 'desc') {
                                    $current_col_x_start_for_cell = $this->marge_gauche;
                                    $current_col_width_for_cell = $this->cols[$colKey]['width'];
                                    $content_to_print_for_column = $component_label_with_qty;
                                    $align = 'L';
                                }

                                if ($current_col_width_for_cell < 0) $current_col_width_for_cell = 0;

                                $pdf->SetXY($current_col_x_start_for_cell + $padding_comp[3], $current_Y_for_components + $padding_comp[0]);
                                $pdf->MultiCell($current_col_width_for_cell - $padding_comp[1] - $padding_comp[3], $line_height, $outputlangs->convToOutputCharset($content_to_print_for_column), 0, $align, 0, 1, '', '', true, 0, false, true, $line_height, 'M');
                            }
                            $current_Y_for_components += $line_height;
                        }
                        $this->setAfterColsLinePositionsData('bom_components_list', $current_Y_for_components, $pdf->getPage());
                    }
                }
                // END: Display MO BOM Components

                $afterPosData = $this->getMaxAfterColsLinePositionsData();
                $parameters = array(
                    'object' => $object,
                    'i' => $i,
                    'pdf' => &$pdf,
                    'curY' => &$curY,
                    'nexY' => &$afterPosData['y'], // This nexY is for the *next* line, or for dashed line drawing.
                    'outputlangs' => $outputlangs,
                    'hidedetails' => $hidedetails
                );
                $reshook = $hookmanager->executeHooks('printPDFline', $parameters, $this);

                if (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) {
                    $tvaligne = $object->lines[$i]->multicurrency_total_tva;
                } else {
                    $tvaligne = $object->lines[$i]->total_tva;
                }

                $localtax1ligne = $object->lines[$i]->total_localtax1;
                $localtax2ligne = $object->lines[$i]->total_localtax2;
                $localtax1_rate = $object->lines[$i]->localtax1_tx;
                $localtax2_rate = $object->lines[$i]->localtax2_tx;
                $localtax1_type = $object->lines[$i]->localtax1_type;
                $localtax2_type = $object->lines[$i]->localtax2_type;

                $vatrate = (string) $object->lines[$i]->tva_tx;

                if ((!isset($localtax1_type) || $localtax1_type == '' || !isset($localtax2_type) || $localtax2_type == '') && (!empty($localtax1_rate) || !empty($localtax2_rate))) {
                    $localtaxtmp_array = getLocalTaxesFromRate($vatrate, 0, $object->thirdparty, $mysoc);
                    $localtax1_type = isset($localtaxtmp_array[0]) ? $localtaxtmp_array[0] : '';
                    $localtax2_type = isset($localtaxtmp_array[2]) ? $localtaxtmp_array[2] : '';
                }

                if ($localtax1_type && $localtax1ligne != 0) {
                    if (empty($this->localtax1[$localtax1_type][$localtax1_rate])) {
                        $this->localtax1[$localtax1_type][$localtax1_rate] = $localtax1ligne;
                    } else {
                        $this->localtax1[$localtax1_type][$localtax1_rate] += $localtax1ligne;
                    }
                }
                if ($localtax2_type && $localtax2ligne != 0) {
                    if (empty($this->localtax2[$localtax2_type][$localtax2_rate])) {
                        $this->localtax2[$localtax2_type][$localtax2_rate] = $localtax2ligne;
                    } else {
                        $this->localtax2[$localtax2_type][$localtax2_rate] += $localtax2ligne;
                    }
                }

                if (($object->lines[$i]->info_bits & 0x01) == 0x01) {
                    $vatrate .= '*';
                }

                if (!isset($this->tva[$vatrate])) {
                    $this->tva[$vatrate] = 0;
                }
                $this->tva[$vatrate] += $tvaligne;
                $vatcode = $object->lines[$i]->vat_src_code;
                if (empty($this->tva_array[$vatrate . ($vatcode ? ' (' . $vatcode . ')' : '')]['amount'])) {
                    $this->tva_array[$vatrate . ($vatcode ? ' (' . $vatcode . ')' : '')]['amount'] = 0;
                }
                $this->tva_array[$vatrate . ($vatcode ? ' (' . $vatcode . ')' : '')] = array('vatrate' => $vatrate, 'vatcode' => $vatcode, 'amount' => $this->tva_array[$vatrate . ($vatcode ? ' (' . $vatcode . ')' : '')]['amount'] + $tvaligne);

                $afterPosData = $this->getMaxAfterColsLinePositionsData(); // This now includes BOM height if one was printed for line $i
                $pdf->setPage($afterPosData['page']);
                $nexY = $afterPosData['y']; // This nexY is the true starting Y for the dashed line or the next actual product line.

                if (getDolGlobalString('MAIN_PDF_DASH_BETWEEN_LINES') && $i < ($nblines - 1) && $nexY < $this->page_hauteur - $heightforfooter - 5) {
                    $pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(80, 80, 80)));
                    $pdf->line($this->marge_gauche, $nexY + 1, $this->page_largeur - $this->marge_droite, $nexY + 1);
                    $pdf->SetLineStyle(array('dash' => 0));
                }

                $nexY += 2; // Space before the next line or end of table
            }

            // This final getMaxAfterColsLinePositionsData call before drawing totals etc.,
            // ensures that the position is correctly updated if the last line had a BOM.
            $afterPosData = $this->getMaxAfterColsLinePositionsData();
            if (isset($afterPosData['y']) && $afterPosData['y'] > $this->page_hauteur - ($heightforfooter + $heightforfreetext + $heightforinfotot)) {
                $pdf->AddPage();
                if (!empty($tplidx)) {
                    $pdf->useTemplate($tplidx);
                }
                $pagenb++;
                $pdf->setPage($pagenb);
            }

            $drawTabNumbPage = $pdf->getNumPages();
            for ($i = $pageposbeforeprintlines; $i <= $drawTabNumbPage; $i++) {
                $pdf->setPage($i);
                $pdf->setPageOrientation('', 0, 0);

                $drawTabHideTop = $hidetop;
                $drawTabTop = $tab_top_newpage;
                $drawTabBottom = $this->page_hauteur - $heightforfooter;
                $hideBottom = 0;

                if ($i == $pageposbeforeprintlines) {
                    $drawTabTop = $tab_top;
                } elseif (!$drawTabHideTop) {
                    if (getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
                        $drawTabTop -= $this->tabTitleHeight;
                    } else {
                        $drawTabHideTop = 1;
                    }
                }

                if ($i == $pdf->getNumPages()) {
                    $drawTabBottom -= $heightforfreetext + $heightforinfotot;
                }

                $drawTabHeight = $drawTabBottom - $drawTabTop;
                $this->_tableau($pdf, $drawTabTop, $drawTabHeight, 0, $outputlangs, $drawTabHideTop, $hideBottom, $object->multicurrency_code, $outputlangsbis);

                $hideFreeText = $i != $pdf->getNumPages() ? 1 : 0;
                $this->_pagefoot($pdf, $object, $outputlangs, $hideFreeText);

                $pdf->setPage($i);
                $pdf->setPageOrientation('', 1, 0);

                if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') && $i != $pageposbeforeprintlines) {
                    $this->_pagehead($pdf, $object, 0, $outputlangs);
                }
                if (!empty($tplidx)) {
                    $pdf->useTemplate($tplidx);
                }
            }

            $pdf->SetTextColor(0, 0, 0);
            $pdf->setPage($pdf->getNumPages());

            $bottomlasttab = $this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;

            $posy = $this->drawInfoTable($pdf, $object, $bottomlasttab, $outputlangs);
            $posy = $this->drawTotalTable($pdf, $object, $deja_regle, $bottomlasttab, $outputlangs);

            if (method_exists($pdf, 'AliasNbPages')) {
                $pdf->AliasNbPages();
            }

            $pdf->Close();
            $pdf->Output($file, 'F');

            $hookmanager->initHooks(array('pdfgeneration'));
            $parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
            $reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);
            if ($reshook < 0) {
                $this->error = $hookmanager->error;
                $this->errors = $hookmanager->errors;
            }

            dolChmod($file);

            $this->result = array('fullpath' => $file);

            return 1;
        } else {
            $this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
            return 0;
        }
    } else {
        $this->error = $langs->transnoentities("ErrorConstantNotDefined", "FACTURE_OUTPUTDIR");
        return 0;
    }
}
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	

	/**
	 *  Show payments table
	 *
	 *  @param	TCPDF		$pdf     		Object PDF
	 *  @param  Commande	$object			Object order
	 *	@param	int			$posy			Position y in PDF
	 *	@param	Translate	$outputlangs	Object langs for output
	 *	@return int							Return integer <0 if KO, >0 if OK
	 */
	protected function drawPaymentsTable(&$pdf, $object, $posy, $outputlangs)
	{
		return 0;
	}

	/**
	 *   Show miscellaneous information (payment mode, payment term, ...)
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		Commande	$object			Object to show
	 *   @param		int			$posy			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @return	int							Pos y
	 */
	protected function drawInfoTable(&$pdf, $object, $posy, $outputlangs)
	{
		global $conf, $mysoc;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$pdf->SetFont('', '', $default_font_size - 1);

		$diffsizetitle = (!getDolGlobalString('PDF_DIFFSIZE_TITLE') ? 3 : $conf->global->PDF_DIFFSIZE_TITLE);

		// If France, show VAT mention if not applicable
		if ($this->emetteur->country_code == 'FR' && empty($mysoc->tva_assuj)) {
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedForInvoice"), 0, 'L', 0);

			$posy = $pdf->GetY() + 4;
		}

		$posxval = 52;

		$diffsizetitle = (!getDolGlobalString('PDF_DIFFSIZE_TITLE') ? 3 : $conf->global->PDF_DIFFSIZE_TITLE);

		// Show payments conditions
		if ($object->cond_reglement_code || $object->cond_reglement) {
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("PaymentConditions").':';
			$pdf->MultiCell(43, 4, $titre, 0, 'L');

			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->SetXY($posxval, $posy);
			$lib_condition_paiement = ($outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code) != 'PaymentCondition'.$object->cond_reglement_code) ? $outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code) : $outputlangs->convToOutputCharset($object->cond_reglement_doc ? $object->cond_reglement_doc : $object->cond_reglement_label);
			$lib_condition_paiement = str_replace('\n', "\n", $lib_condition_paiement);
			if ($object->deposit_percent > 0) {
				$lib_condition_paiement = str_replace('__DEPOSIT_PERCENT__', (string) $object->deposit_percent, $lib_condition_paiement);
			}
			$pdf->MultiCell(67, 4, $lib_condition_paiement, 0, 'L');

			$posy = $pdf->GetY() + 3;
		}

		// Check a payment mode is defined
		/* Not used with orders
		if (empty($object->mode_reglement_code)
			&& ! $conf->global->FACTURE_CHQ_NUMBER
			&& ! $conf->global->FACTURE_RIB_NUMBER)
		{
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->SetTextColor(200,0,0);
			$pdf->SetFont('','B', $default_font_size - 2);
			$pdf->MultiCell(80, 3, $outputlangs->transnoentities("ErrorNoPaiementModeConfigured"),0,'L',0);
			$pdf->SetTextColor(0,0,0);

			$posy=$pdf->GetY()+1;
		}
		*/
		/* TODO
		else if (!empty($object->availability_code))
		{
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->SetTextColor(200,0,0);
			$pdf->SetFont('','B', $default_font_size - 2);
			$pdf->MultiCell(80, 3, $outputlangs->transnoentities("AvailabilityPeriod").': '.,0,'L',0);
			$pdf->SetTextColor(0,0,0);

			$posy=$pdf->GetY()+1;
		}*/

		// Show planned date of delivery
		if (!empty($object->delivery_date)) {
			$outputlangs->load("sendings");
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("DateDeliveryPlanned").':';
			$pdf->MultiCell(80, 4, $titre, 0, 'L');
			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->SetXY($posxval, $posy);
			$dlp = dol_print_date($object->delivery_date, "daytext", false, $outputlangs, true);
			$pdf->MultiCell(80, 4, $dlp, 0, 'L');

			$posy = $pdf->GetY() + 1;
		} elseif ($object->availability_code || $object->availability) {    // Show availability conditions
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("AvailabilityPeriod").':';
			$pdf->MultiCell(80, 4, $titre, 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->SetXY($posxval, $posy);
			$lib_availability = $outputlangs->transnoentities("AvailabilityType".$object->availability_code) != 'AvailabilityType'.$object->availability_code ? $outputlangs->transnoentities("AvailabilityType".$object->availability_code) : $outputlangs->convToOutputCharset(isset($object->availability) ? $object->availability : '');
			$lib_availability = str_replace('\n', "\n", $lib_availability);
			$pdf->MultiCell(80, 4, $lib_availability, 0, 'L');

			$posy = $pdf->GetY() + 1;
		}

		// Show payment mode
		if ($object->mode_reglement_code
			&& $object->mode_reglement_code != 'CHQ'
			&& $object->mode_reglement_code != 'VIR') {
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("PaymentMode").':';
			$pdf->MultiCell(80, 5, $titre, 0, 'L');

			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->SetXY($posxval, $posy);
			$lib_mode_reg = $outputlangs->transnoentities("PaymentType".$object->mode_reglement_code) != 'PaymentType'.$object->mode_reglement_code ? $outputlangs->transnoentities("PaymentType".$object->mode_reglement_code) : $outputlangs->convToOutputCharset($object->mode_reglement);
			$pdf->MultiCell(80, 5, $lib_mode_reg, 0, 'L');

			$posy = $pdf->GetY() + 2;
		}

		// Show payment mode CHQ
		if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'CHQ') {
			// Si mode reglement non force ou si force a CHQ
			if (getDolGlobalString('FACTURE_CHQ_NUMBER')) {
				if (getDolGlobalInt('FACTURE_CHQ_NUMBER') > 0) {
					$account = new Account($this->db);
					$account->fetch(getDolGlobalString('FACTURE_CHQ_NUMBER'));

					$pdf->SetXY($this->marge_gauche, $posy);
					$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo', $account->owner_name), 0, 'L', 0);
					$posy = $pdf->GetY() + 1;

					if (!getDolGlobalString('MAIN_PDF_HIDE_CHQ_ADDRESS')) {
						$pdf->SetXY($this->marge_gauche, $posy);
						$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
						$pdf->MultiCell(100, 3, $outputlangs->convToOutputCharset($account->owner_address), 0, 'L', 0);
						$posy = $pdf->GetY() + 2;
					}
				}
				if ($conf->global->FACTURE_CHQ_NUMBER == -1) {
					$pdf->SetXY($this->marge_gauche, $posy);
					$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
					$pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo', $this->emetteur->name), 0, 'L', 0);
					$posy = $pdf->GetY() + 1;

					if (!getDolGlobalString('MAIN_PDF_HIDE_CHQ_ADDRESS')) {
						$pdf->SetXY($this->marge_gauche, $posy);
						$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
						$pdf->MultiCell(100, 3, $outputlangs->convToOutputCharset($this->emetteur->getFullAddress()), 0, 'L', 0);
						$posy = $pdf->GetY() + 2;
					}
				}
			}
		}

		// If payment mode not forced or forced to VIR, show payment with BAN
		if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR') {
			if ($object->fk_account > 0 || $object->fk_bank > 0 || getDolGlobalInt('FACTURE_RIB_NUMBER')) {
				$bankid = ($object->fk_account <= 0 ? $conf->global->FACTURE_RIB_NUMBER : $object->fk_account);
				if ($object->fk_bank > 0) {
					$bankid = $object->fk_bank; // For backward compatibility when object->fk_account is forced with object->fk_bank
				}
				$account = new Account($this->db);
				$account->fetch($bankid);

				$curx = $this->marge_gauche;
				$cury = $posy;

				$posy = pdf_bank($pdf, $outputlangs, $curx, $cury, $account, 0, $default_font_size);

				$posy += 2;
			}
		}

		return $posy;
	}


	/**
	 *	Show total to pay
	 *
	 *	@param	TCPDF		$pdf            Object PDF
	 *	@param  Commande	$object         Object to show
	 *	@param  int			$deja_regle     Montant deja regle
	 *	@param	int			$posy			Position depart
	 *	@param	Translate	$outputlangs	Object langs
	 *  @param  Translate	$outputlangsbis	Object lang for output bis
	 *	@return int							Position pour suite
	 */
	protected function drawTotalTable(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis = null)
	{
		global $conf, $mysoc, $hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
			$outputlangsbis = new Translate('', $conf);
			$outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
			$outputlangsbis->loadLangs(array("main", "dict", "companies", "bills", "products", "propal"));
			$default_font_size--;
		}

		$tab2_top = $posy;
		$tab2_hl = 4;
		$pdf->SetFont('', '', $default_font_size - 1);

		// Total table
		$col1x = 120;
		$col2x = 170;
		if ($this->page_largeur < 210) { // To work with US executive format
			$col2x -= 20;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index = 0;

		// Get Total HT
		$total_ht = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1 ? $object->multicurrency_total_ht : $object->total_ht);

		// Total remise
		$total_line_remise = 0;
		foreach ($object->lines as $i => $line) {
			$resdiscount = pdfGetLineTotalDiscountAmount($object, $i, $outputlangs, 2);
			$total_line_remise += (is_numeric($resdiscount) ? $resdiscount : 0);
			// Gestion remise sous forme de ligne nÃƒÂ©gative
			if ($line->total_ht < 0) {
				$total_line_remise += -$line->total_ht;
			}
		}
		$total_line_remise = (float) price2num($total_line_remise, 'MT', 1);

		if ($total_line_remise > 0) {
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalDiscount").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalDiscount") : ''), 0, 'L', 1);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl);
			$pdf->MultiCell($largcol2, $tab2_hl, price($total_line_remise, 0, $outputlangs), 0, 'R', 1);

			$index++;

			// Show total NET before discount
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $tab2_top);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHTBeforeDiscount").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalHTBeforeDiscount") : ''), 0, 'L', 1);
			$pdf->SetXY($col2x, $tab2_top);
			$pdf->MultiCell($largcol2, $tab2_hl, price($total_line_remise + $total_ht, 0, $outputlangs), 0, 'R', 1);

			$index++;
		}

		// Total HT
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalHT") : ''), 0, 'L', 1);
		$total_ht = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, price($total_ht + (!empty($object->remise) ? $object->remise : 0), 0, $outputlangs), 0, 'R', 1);

		// Show VAT by rates and total
		$pdf->SetFillColor(248, 248, 248);

		$total_ttc = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;

		$this->atleastoneratenotnull = 0;
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$tvaisnull = (!empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000']));
			if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_IFNULL') && $tvaisnull) {
				// Nothing to do
			} else {
				//Local tax 1 before VAT
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;
							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX1_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}

				//Local tax 2 before VAT
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT2", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX2_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}

				// VAT
				foreach ($this->tva_array as $tvakey => $tvaval) {
					if ($tvakey != 0) {    // On affiche pas taux 0
						$this->atleastoneratenotnull++;

						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

						$tvacompl = '';
						if (preg_match('/\*/', $tvakey)) {
							$tvakey = str_replace('*', '', $tvakey);
							$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
						}
						$totalvat = $outputlangs->transcountrynoentities("TotalVAT", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalVAT", $mysoc->country_code) : '');
						$totalvat .= ' ';
						if (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'rateonly') {
							$totalvat .= vatrate($tvaval['vatrate'], true).$tvacompl;
						} elseif (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'codeonly') {
							$totalvat .= $tvaval['vatcode'].$tvacompl;
						} elseif (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
							$totalvat .= $tvacompl;
						} else {
							$totalvat .= vatrate($tvaval['vatrate'], true).($tvaval['vatcode'] ? ' ('.$tvaval['vatcode'].')' : '').$tvacompl;
						}
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price(price2num($tvaval['amount'], 'MT'), 0, $outputlangs), 0, 'R', 1);
					}
				}

				//Local tax 1 after VAT
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX1_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}

				//Local tax 2 after VAT
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						// retrieve global local tax
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', (string) $tvakey)) {
								$tvakey = str_replace('*', '', (string) $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT2", $mysoc->country_code) : '');
							$totalvat .= ' ';

							if (getDolGlobalString('PDF_LOCALTAX2_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}
				//}

				// Total TTC
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->SetFillColor(224, 224, 224);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalTTC", $mysoc->country_code) : ''), $useborder, 'L', 1);

				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, price($total_ttc, 0, $outputlangs), $useborder, 'R', 1);
			}
		}

		$pdf->SetTextColor(0, 0, 0);

		$creditnoteamount = 0;
		$depositsamount = 0;
		//$creditnoteamount=$object->getSumCreditNotesUsed();
		//$depositsamount=$object->getSumDepositsUsed();
		//print "x".$creditnoteamount."-".$depositsamount;exit;
		$resteapayer = price2num($total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (!empty($object->paye)) {
			$resteapayer = 0;
		}

		if ($deja_regle > 0) {
			// Already paid + Deposits
			$index++;

			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("AlreadyPaid") : ''), 0, 'L', 0);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle, 0, $outputlangs), 0, 'R', 0);

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("RemainderToPay") : ''), $useborder, 'L', 1);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($resteapayer, 0, $outputlangs), $useborder, 'R', 1);

			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetTextColor(0, 0, 0);
		}

		$parameters = array('pdf' => &$pdf, 'object' => &$object, 'outputlangs' => $outputlangs, 'index' => &$index, 'posy' => $posy);

		$reshook = $hookmanager->executeHooks('afterPDFTotalTable', $parameters, $this); // Note that $action and $object may have been modified by some hooks
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
		}

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   Show table for lines
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		float|int	$tab_top		Top position of table
	 *   @param		float|int	$tab_height		Height of table (rectangle)
	 *   @param		int			$nexY			Y (not used)
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
	 *   @param		int			$hidebottom		Hide bottom bar of array
	 *   @param		string		$currency		Currency code
	 *   @param		Translate	$outputlangsbis	Langs object bis
	 *   @return	void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '', $outputlangsbis = null)
	{
		global $conf;

		// Force to disable hidetop and hidebottom
		$hidebottom = 0;
		if ($hidetop) {
			$hidetop = -1;
		}

		$currency = !empty($currency) ? $currency : $conf->currency;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $default_font_size - 2);

		if (empty($hidetop)) {
			$titre = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency".$currency));
			if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
				$titre .= ' - '.$outputlangsbis->transnoentities("AmountInCurrency", $outputlangsbis->transnoentitiesnoconv("Currency".$currency));
			}

			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top - 4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);

			//$conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR='230,230,230';
			if (getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
				$pdf->RoundedRect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, $this->corner_radius, '1001', 'F', null, explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
			}
		}

		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetFont('', '', $default_font_size - 1);

		// Output Rect
		$this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, $hidetop, $hidebottom, 'D'); // Rect takes a length in 3rd parameter and 4th parameter


		$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);

		if (empty($hidetop)) {
			$pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight); // line takes a position y in 2nd parameter and 4th parameter
		}
	}
/**
 * Show top header of page.
 *
 * @param TCPDF       $pdf            Object PDF
 * @param Commande    $object         Object to show
 * @param int         $showaddress    0=no, 1=yes
 * @param Translate   $outputlangs    Object lang for output
 * @param Translate   $outputlangsbis Object lang for output bis
 * @param string      $titlekey       Translation key to show as title of document
 * @return array<string, int|float>   top shift of linked object lines
 */
protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null, $titlekey = "PdfOrderTitle")
{
    // phpcs:enable
    global $conf, $langs, $hookmanager, $mysoc;

    $ltrdirection = 'L';
    if ($outputlangs->trans("DIRECTION") == 'rtl') {
        $ltrdirection = 'R';
    }

    // Load translation files required by page
    $outputlangs->loadLangs(array("main", "bills", "propal", "orders", "companies"));

    $default_font_size = pdf_getPDFFontSize($outputlangs);

    pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

    $pdf->SetTextColor(0, 0, 60);
    $pdf->SetFont('', 'B', $default_font_size + 3);

    $posy = $this->marge_haute;
    $posx = $this->marge_gauche;
    $page_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

    // ===== LEFT COLUMN =====

    // Company Logo Section (aligned left)
    if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
        if ($this->emetteur->logo) {
            $logodir = $conf->mycompany->dir_output;
            if (!empty(getMultidirOutput($mysoc, 'mycompany'))) {
                $logodir = getMultidirOutput($mysoc, 'mycompany');
            }
            $logo = !getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? $logodir . '/logos/thumbs/' . $this->emetteur->logo_small : $logodir . '/logos/' . $this->emetteur->logo;
            if (is_readable($logo)) {
                $height = pdf_getHeightForLogo($logo);
                $logo_width = 70; // Increased size for bigger logo
                $pdf->Image($logo, $this->marge_gauche, $posy, $logo_width, $height); // Aligned left
                $posy += $height + 8; // Added more spacing after logo
            } else {
                $pdf->SetTextColor(200, 0, 0);
                $pdf->SetFont('', 'B', $default_font_size - 2);
                $pdf->SetXY($this->marge_gauche, $posy);
                $pdf->MultiCell($page_width / 2, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
                $pdf->MultiCell($page_width / 2, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
                $posy += 15;
            }
        } else {
            $pdf->SetFont('', 'B', $default_font_size + 2);
            $pdf->SetXY($this->marge_gauche, $posy);
            $pdf->MultiCell($page_width / 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
            $posy += 8;
        }
    }

    // Company Information
    $pdf->SetFont('', 'B', $default_font_size);
    $pdf->SetXY($this->marge_gauche, $posy);
    $pdf->MultiCell($page_width / 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
    $posy += 5;

    // Website URL
    $pdf->SetFont('', '', $default_font_size - 1);
    $pdf->SetXY($this->marge_gauche, $posy);
    $pdf->MultiCell($page_width / 2, 4, 'www.informatics-dz.com', 0, 'L');
    $posy += 5;

    // Add full-width box with the new text
   // In the _pagehead method, around the section where the warranty certificate title is displayed
$pdf->SetFillColor(230, 230, 230); // Light gray background
$pdf->Rect($this->marge_gauche, $posy, $page_width, 10, 'F'); // Full width box

// Set font to support Arabic (e.g., aealarabiya or dejavusans)
$pdf->SetFont('aealarabiya', '', $default_font_size + 2); // Use aealarabiya or dejavusans
$pdf->SetXY($this->marge_gauche, $posy + 2);
$pdf->MultiCell($page_width, 5, $outputlangs->convToOutputCharset('CERTIFICAT DE GARANTIE                     الضمان شهادة'), 0, 'C');
$posy += 15;

    // ===== RIGHT COLUMN =====

    $w = 100;
    $posx = $this->page_largeur - $this->marge_droite - $w;
    $right_posy = $this->marge_haute;

    // Document title and reference
    $pdf->SetFont('', 'B', $default_font_size + 3);
    $pdf->SetXY($posx, $right_posy);
    $pdf->SetTextColor(0, 0, 60);
    $title = $outputlangs->transnoentities($titlekey);
    if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
        $title .= ' - ';
        $title .= $outputlangsbis->transnoentities($titlekey);
    }
    $title .= ' ' . $outputlangs->convToOutputCharset($object->ref);
    if ($object->statut == $object::STATUS_DRAFT) {
        $pdf->SetTextColor(128, 0, 0);
        $title .= ' - ' . $outputlangs->transnoentities("NotValidated");
    }
    $pdf->MultiCell($w, 3, $title, '', 'R');
    $right_posy = $pdf->getY() + 5; // Ensure proper spacing

    // Reference info
    $pdf->SetFont('', '', $default_font_size - 1);
    $pdf->SetTextColor(0, 0, 60);

    if ($object->ref_client) {
        $pdf->SetXY($posx, $right_posy);
        $pdf->MultiCell($w, 3, $outputlangs->transnoentities("RefCustomer") . " : " .
                       dol_trunc($outputlangs->convToOutputCharset($object->ref_client), 65), '', 'R');
        $right_posy += 4;
    }

    if (getDolGlobalInt('PDF_SHOW_PROJECT_TITLE')) {
        $object->fetchProject();
        if (!empty($object->project->ref)) {
            $pdf->SetXY($posx, $right_posy);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities("Project") . " : " .
                           (empty($object->project->title) ? '' : $object->project->title), '', 'R');
            $right_posy += 4;
        }
    }

    if (getDolGlobalInt('PDF_SHOW_PROJECT')) {
        $object->fetchProject();
        if (!empty($object->project->ref)) {
            $outputlangs->load("projects");
            $pdf->SetXY($posx, $right_posy);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities("RefProject") . " : " .
                           (empty($object->project->ref) ? '' : $object->project->ref), '', 'R');
            $right_posy += 4;
        }
    }

    // Order date
    $pdf->SetXY($posx, $right_posy);
    $title = $outputlangs->transnoentities("OrderDate");
    if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
        $title .= ' - ' . $outputlangsbis->transnoentities("DateInvoice");
    }
    $pdf->MultiCell($w, 3, $title . " : " . dol_print_date($object->date, "day", false, $outputlangs, true), '', 'R');
    $right_posy += 4;

    // Customer codes if enabled
    if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
        $pdf->SetXY($posx, $right_posy);
        $pdf->MultiCell($w, 3, $outputlangs->transnoentities("CustomerCode") . " : " .
                       $outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
        $right_posy += 4;
    }

    if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && !empty($object->thirdparty->code_compta_client)) {
        $pdf->SetXY($posx, $right_posy);
        $pdf->MultiCell($w, 3, $outputlangs->transnoentities("CustomerAccountancyCode") . " : " .
                       $outputlangs->transnoentities($object->thirdparty->code_compta_client), '', 'R');
        $right_posy += 4;
    }

    // Get contact
    if (getDolGlobalInt('DOC_SHOW_FIRST_SALES_REP')) {
        $arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
        if (count($arrayidcontact) > 0) {
            $usertmp = new User($this->db);
            $usertmp->fetch($arrayidcontact[0]);
            $pdf->SetXY($posx, $right_posy);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities("SalesRepresentative") . " : " .
                           $usertmp->getFullName($langs), '', 'R');
            $right_posy += 4;
        }
    }

    // ===== LINKED OBJECTS =====

    $right_posy += 2;
    $current_y = $pdf->getY();
    $posy_ref = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx, $right_posy, $w, 3, 'R', $default_font_size);
    if ($current_y < $pdf->getY()) {
        $top_shift = $pdf->getY() - $current_y;
    } else {
        $top_shift = 0;
    }

    // ===== ADDRESS FRAMES =====

    if ($showaddress) {
        // Calculate the start position for address frames
        // Leave enough space for the header content
        $address_start_y = max($posy, $right_posy) + 15; // Added extra space between header and address blocks

        // Sender properties
        $carac_emetteur = '';
        // Add internal contact of object if defined
        $arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
        if (count($arrayidcontact) > 0) {
            $object->fetch_user($arrayidcontact[0]);
            $labelbeforecontactname = ($outputlangs->transnoentities("FromContactName") != 'FromContactName' ?
                                      $outputlangs->transnoentities("FromContactName") :
                                      $outputlangs->transnoentities("Name"));
            $carac_emetteur .= ($carac_emetteur ? "\n" : '') . $labelbeforecontactname . " " .
                              $outputlangs->convToOutputCharset($object->user->getFullName($outputlangs));
            $carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') ||
                              getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ' (' : '';
            $carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') &&
                              !empty($object->user->office_phone)) ? $object->user->office_phone : '';
            $carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') &&
                              getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ', ' : '';
            $carac_emetteur .= (getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT') &&
                              !empty($object->user->email)) ? $object->user->email : '';
            $carac_emetteur .= (getDolGlobalInt('PDF_SHOW_PHONE_AFTER_USER_CONTACT') ||
                              getDolGlobalInt('PDF_SHOW_EMAIL_AFTER_USER_CONTACT')) ? ')' : '';
            $carac_emetteur .= "\n";
        }

        $carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);

        // Show sender
        $posy = $address_start_y;
        $posx = $this->marge_gauche;
        if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
            $posx = $this->page_largeur - $this->marge_droite - 80;
        }

        $hautcadre = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
        $widthrecbox = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;

        // Show sender frame
        if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY($posx, $posy - 5);
            $pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillFrom"), 0, $ltrdirection);
            $pdf->SetXY($posx, $posy);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->RoundedRect($posx, $posy, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'F');
            $pdf->SetTextColor(0, 0, 60);
        }

        // Show sender name
        if (!getDolGlobalString('MAIN_PDF_NO_SENDER_NAME')) {
            $pdf->SetXY($posx + 2, $posy + 3);
            $pdf->SetFont('', 'B', $default_font_size);
            $pdf->MultiCell($widthrecbox - 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
            $posy = $pdf->getY();
        }

        // Show sender information
        $pdf->SetXY($posx + 2, $posy);
        $pdf->SetFont('', '', $default_font_size - 1);
        $pdf->MultiCell($widthrecbox - 2, 4, $carac_emetteur, 0, $ltrdirection);

        // If CUSTOMER contact defined, we use it
        $usecontact = false;
        $arrayidcontact = $object->getIdContact('external', 'CUSTOMER');
        if (count($arrayidcontact) > 0) {
            $usecontact = true;
            $result = $object->fetch_contact($arrayidcontact[0]);
        }

        // Recipient name
        if ($usecontact && $object->contact->socid != $object->thirdparty->id &&
            getDolGlobalInt('MAIN_USE_COMPANY_NAME_OF_CONTACT')) {
            $thirdparty = $object->contact;
        } else {
            $thirdparty = $object->thirdparty;
        }

        if (is_object($thirdparty)) {
            $carac_client_name = pdfBuildThirdpartyName($thirdparty, $outputlangs);
        }

        $mode = 'target';
        $carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty,
                                         ($usecontact ? $object->contact : ''),
                                         ($usecontact ? 1 : 0), $mode, $object);

        // Show recipient
        $widthrecbox = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
        if ($this->page_largeur < 210) {
            $widthrecbox = 84; // To work with US executive format
        }
        $posy = $address_start_y;
        $posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
        if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
            $posx = $this->marge_gauche;
        }

        // Store Y position for the top of the recipient frame
        $recipient_frame_y_start = $posy;

        // Print "BillTo" title (above the frame)
        if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY($posx + 2, $recipient_frame_y_start - 5);
            $pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo"), 0, $ltrdirection);
        }

        // Y position where actual content inside the box starts
        $y_text_content_start = $recipient_frame_y_start + 3;
        $pdf->SetXY($posx + 2, $y_text_content_start);

        // Show recipient name
        $pdf->SetFont('', 'B', $default_font_size);
        $pdf->MultiCell($widthrecbox, 2, $carac_client_name, 0, $ltrdirection);
        $current_y_tracker = $pdf->GetY();

        // Show recipient information (address)
        $pdf->SetFont('', '', $default_font_size - 1);
        $pdf->SetXY($posx + 2, $current_y_tracker);
        $pdf->MultiCell($widthrecbox, 4, $carac_client, 0, $ltrdirection);
        $current_y_tracker = $pdf->GetY();

        // Add Client Phone
        if (!empty($object->thirdparty->phone)) {
            $current_y_tracker += 1; // Add a small top margin
            $pdf->SetFont('', '', $default_font_size - 1); // Ensure font
            $pdf->SetXY($posx + 2, $current_y_tracker);
            $pdf->MultiCell($widthrecbox, 4, $outputlangs->transnoentities("PhonePro").": ".$outputlangs->convToOutputCharset($object->thirdparty->phone), 0, $ltrdirection);
            $current_y_tracker = $pdf->GetY();
        }

        // Add Client Email
        if (!empty($object->thirdparty->email)) {
            $current_y_tracker += 1; // Add a small top margin
            $pdf->SetFont('', '', $default_font_size - 1); // Ensure font
            $pdf->SetXY($posx + 2, $current_y_tracker);
            $pdf->MultiCell($widthrecbox, 4, $outputlangs->transnoentities("Email").": ".$outputlangs->convToOutputCharset($object->thirdparty->email), 0, $ltrdirection);
            $current_y_tracker = $pdf->GetY(); 
        }
        
        $y_text_content_end = $current_y_tracker;

        // Use sender's box height for recipient box. $hautcadre should hold sender's box height from earlier in the method.
        // Note: This assumes $hautcadre (sender's box height) is still in scope and holds the correct value.
        // The sender box section defines: $hautcadre = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
        // This value should be used here directly.
        
        // Draw the recipient box frame using sender's box height
        if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
            // $sender_box_height should be the value of $hautcadre from the sender's block.
            // Assuming $hautcadre still holds that value. If not, it needs to be explicitly passed or re-fetched.
            // For this diff, we rely on $hautcadre being the sender's box height.
            $pdf->RoundedRect($posx, $recipient_frame_y_start, $widthrecbox, $hautcadre, $this->corner_radius, '1234', 'D');
        }
        
        // Update $posy to be the bottom of this recipient box (now with fixed height) for subsequent elements like shipping.
        $posy = $recipient_frame_y_start + $hautcadre; // Use sender's $hautcadre

        // Show shipping address - only if enabled and needed
        $shipp_shift = 0; // This was defined before, but it's related to overall page shift, not direct Y.
                         // The shipping block calculation starts from $posy (updated above) and $hautcadre (its own).
        if (getDolGlobalInt('SALES_ORDER_SHOW_SHIPPING_ADDRESS')) {
            $idaddressshipping = $object->getIdContact('external', 'SHIPPING');
            // Note: $hautcadre below is for the *shipping* box, defined locally here.
            // The $posy used here is the updated $posy from after the recipient box.
            $shipping_box_top_y = $posy + 10; // Add 10 units space between recipient and shipping boxes

            if (!empty($idaddressshipping)) {
                $contactshipping = $object->fetch_contact($idaddressshipping[0]);
                $companystatic = new Societe($this->db);
                $companystatic->fetch($object->contact->fk_soc);
                $carac_client_name_shipping = pdfBuildThirdpartyName($object->contact, $outputlangs);
                $carac_client_shipping = pdf_build_address($outputlangs, $this->emetteur, $companystatic,
                                                        $object->contact, ($usecontact ? 1 : 0), 'target', $object);
            } else {
                $carac_client_name_shipping = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
                $carac_client_shipping = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty,
                                                        '', 0, 'target', $object);
            }

            if (!empty($carac_client_shipping)) {
                $posy += $hautcadre + 10; // Add proper spacing between address blocks

                $hautcadre_shipping = $hautcadre - 10; // Height for the shipping address is smaller

                // Show shipping frame
                $pdf->SetXY($posx + 2, $posy - 5);
                $pdf->SetFont('', '', $default_font_size - 2);
                $pdf->MultiCell($widthrecbox, '', $outputlangs->transnoentities('ShippingTo'), 0, 'L', 0);
                $pdf->RoundedRect($posx, $posy, $widthrecbox, $hautcadre_shipping, $this->corner_radius, '1234', 'D');

                // Show shipping name
                $pdf->SetXY($posx + 2, $posy + 1);
                $pdf->SetFont('', 'B', $default_font_size);
                $pdf->MultiCell($widthrecbox - 2, 2, $carac_client_name_shipping, '', 'L');

                $posy = $pdf->getY();

                // Show shipping information
                $pdf->SetXY($posx + 2, $posy);
                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->MultiCell($widthrecbox - 2, 2, $carac_client_shipping, '', 'L');

                $shipp_shift = $hautcadre_shipping + 10;
            }
        }
    }

    $pdf->SetTextColor(0, 0, 0);

    $pagehead = array('top_shift' => $top_shift, 'shipp_shift' => $shipp_shift);

    return $pagehead;
}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   	Show footer of page. Need this->emetteur object
	 *
	 *   	@param	TCPDF		$pdf     			PDF
	 * 		@param	Commande	$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @return	int								Return height of bottom margin including footer text
	 */


/**
 * Show footer of page. Need this->emetteur object
 *
 * @param TCPDF       $pdf            PDF
 * @param Commande    $object         Object to show
 * @param Translate   $outputlangs    Object lang for output
 * @param int         $hidefreetext   1=Hide free text
 * @return int                        Return height of bottom margin including footer text
 */
/**
 * Show footer of page. Need this->emetteur object
 *
 * @param TCPDF       $pdf            PDF
 * @param Commande    $object         Object to show
 * @param Translate   $outputlangs    Object lang for output
 * @param int         $hidefreetext   1=Hide free text
 * @return int                        Return height of bottom margin including footer text
 */
protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
{
    global $conf;

    $showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
    $default_font_size = pdf_getPDFFontSize($outputlangs);
    $width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

    // Standard footer content
    $height = pdf_pagefoot($pdf, $outputlangs, 'ORDER_FREE_TEXT', $this->emetteur, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, $showdetails, $hidefreetext, $this->page_largeur, $this->watermark);

    // Add warranty conditions only on the last page
// In _pagefoot method, replace the warranty conditions block
if ($pdf->getPage() == $pdf->getNumPages()) {
    $posy = $this->page_hauteur - $this->marge_basse - 40; // Reserve space for warranty conditions
    $warranty_conditions = "شروط الضمان:\n";
    $warranty_conditions .= "1- تضمن الشركة للزبون العتاد المباع، ضد كل عيوب التصنيع والعمالة ضمن المدة المحددة ابتداء من تاريخ الشراء.\n";
    $warranty_conditions .= "2- نظام التشغيل والبرامج + نضائد الكمبيوتر المحمول ولوحات المفاتيح وكذا مقود اللعب، الفارة، مكبرات الصوت الفلاشديسك والمستهلكات مضمونة فقط عند أول تشغيل.\n";
    $warranty_conditions .= "5- تثبيت البرمجيات غير مضمون.\n";
    $warranty_conditions .= "7- لا تضمن الشركة أن هذا العتاد سيشتغل بصفة غير منقطعة أو دون خطأ في هذا العتاد.\n";
    $warranty_conditions .= "8- الضمان لا يشمل إرجاع المنتوج أو استبداله، تمنح الشركة مدة 3 أيام من تاريخ استلام المنتوج كأقصى حد لإرجاعه يتم فيها مراجعة المنتوج وتطبيق مستحقات قدرها 5% من سعر المنتوج -(لا تشمل مستحقات التوصيل)-.\n";
    $warranty_conditions .= "9- على الزبون الحفاظ على التغليف خلال مدة ضمان.\n";
    $warranty_conditions .= "10- الضمان لا يشمل: القيام بكسر السرعة OVER CLOCK / الصيانة سيئة / تغيير أو استعمال غير مرخصين / استعمال بطاقة امتداد غير معتمدة / حالات نقل سيئة. وفي حالة خلل في الجهاز يجب على الزبون إرجاعه للشركة خلال فترة الضمان في تغليفه الأصلي.\n";
    $warranty_conditions .= "11- الضمان على الطابعة يشمل إشتغالها فقط، ولا يشمل أخطاء الطباعة أو سوء ملء الخزان الخاص بها.";
  
    $pdf->SetFont('aealarabiya', '', $default_font_size - 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($this->marge_gauche, $posy);
    $pdf->SetFillColor(255, 255, 255); // Changed to white
    $pdf->RoundedRect($this->marge_gauche, $posy, $width, 40, $this->corner_radius, '1234', 'F');
    $pdf->SetXY($this->marge_gauche + 2, $posy + 2);
    $pdf->MultiCell($width - 4, 4, $outputlangs->convToOutputCharset($warranty_conditions), 0, 'R');
    $height += 40; // Add height of warranty conditions
}

    return $height;
}

	/**
	 *   	Define Array Column Field
	 *
	 *   	@param	Commande		$object    		common object
	 *   	@param	Translate		$outputlangs    langs
	 *      @param	int				$hidedetails	Do not show line details
	 *      @param	int				$hidedesc		Do not show desc
	 *      @param	int				$hideref		Do not show ref
	 *      @return	void
	 */
/**
 * Define Array Column Field
 *
 * @param Commande    $object        Common object
 * @param Translate   $outputlangs   Langs object
 * @param int         $hidedetails   Do not show line details
 * @param int         $hidedesc      Do not show desc
 * @param int         $hideref       Do not show ref
 * @return void
 */
public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
{
    global $hookmanager;

    // Default field style for content
    $this->defaultContentsFieldsStyle = array(
        'align' => 'R', // R,C,L
        'padding' => array(1, 0.5, 1, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
    );

    // Default field style for titles
    $this->defaultTitlesFieldsStyle = array(
        'align' => 'C', // R,C,L
        'padding' => array(0.5, 0, 0.5, 0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
    );

    $rank = 0;
    $this->cols['position'] = array(
        'rank' => $rank,
        'width' => 10,
        'status' => (getDolGlobalInt('PDF_ERATOSTHENE_ADD_POSITION') || getDolGlobalInt('PDF_ERATOSHTENE_ADD_POSITION')) ? true : (getDolGlobalInt('PDF_ADD_POSITION') ? true : false),
        'title' => array(
            'textkey' => '#',
            'align' => 'C',
            'padding' => array(0.5, 0.5, 0.5, 0.5),
        ),
        'content' => array(
            'align' => 'C',
            'padding' => array(1, 0.5, 1, 1.5),
        ),
    );

    $rank = 5;
$this->cols['desc'] = array(
    'rank' => $rank,
    'width' => 60, // Set to fixed width 60mm
    'status' => true,
    'title' => array(
        'textkey' => 'Reference', // Change to 'Reference' or 'ProductName'
    ),
    'content' => array(
        'align' => 'L', // Ensure this is explicitly L
        'padding' => array(1, 0.5, 1, 0.5), // Adjusted left padding from 1.5 to 0.5
    ),
);

    $rank += 10;
    $this->cols['photo'] = array(
        'rank' => $rank,
        'width' => (!getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH') ? 20 : getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH')),
        'status' => false,
        'title' => array(
            'textkey' => 'Photo',
            'label' => ' '
        ),
        'content' => array(
            'padding' => array(0, 0, 0, 0),
        ),
        'border-left' => false,
    );

    if (getDolGlobalInt('MAIN_GENERATE_ORDERS_WITH_PICTURE') && !empty($this->atleastonephoto)) {
        $this->cols['photo']['status'] = true;
    }

    $rank += 10;
    $this->cols['vat'] = array(
        'rank' => $rank,
        'status' => false, // Disabled: Sales Tax column removed
        'width' => 16,
        'title' => array(
            'textkey' => 'VAT'
        ),
        'border-left' => true,
    );

    // if (!getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT') && !getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_COLUMN')) {
    //     $this->cols['vat']['status'] = true; // Keep this commented to ensure it's always off
    // }

    $rank += 10;
    $this->cols['subprice'] = array(
        'rank' => $rank,
        'width' => 19,
        'status' => true,
        'title' => array(
            'textkey' => 'PriceUHT'
        ),
        'content' => array(
            'align' => 'C',
        ),
        'border-left' => true,
    );

    $tmpwidth = 0;
    $nblines = count($object->lines);
    for ($i = 0; $i < $nblines; $i++) {
        $tmpwidth2 = dol_strlen(dol_string_nohtmltag(pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails)));
        $tmpwidth = max($tmpwidth, $tmpwidth2);
    }
    if ($tmpwidth > 10) {
        $this->cols['subprice']['width'] += (2 * ($tmpwidth - 10));
    }

    $rank += 10;
  $this->cols['qty'] = array(
        'rank' => $rank,
        'width' => 16,
        'status' => true,
        'title' => array(
            'textkey' => 'Qty'
        ),
        'content' => array(
            'align' => 'C',
        ),
        'border-left' => true,
    );

    // Add Serial Number Column
    $rank += 10;
    $this->cols['serialnumber'] = array(
        'rank' => $rank,
        'width' => 30, // Increased width to 30mm
        'status' => false, // default to false, will be set based on data
        'title' => array(
            'textkey' => 'LotSerial', // Use Dolibarr translation key
        ),
        'content' => array(
            'align' => 'L',
            'padding' => array(1, 0.5, 1, 0.5),
        ),
        'border-left' => true,
    );


 // Add Garantie Column
    $rank += 10;
    $this->cols['garantie'] = array(
        'rank' => $rank,
        'width' => 15, // in mm, adjust as needed
        'status' => false, // default to false, will be set based on data
        'title' => array(
            'textkey' => 'Warranty', // Use Dolibarr translation key or custom key
        ),
        'content' => array(
            'align' => 'L',
            'padding' => array(1, 0.5, 1, 0.5),
        ),
        'border-left' => true,
    );


    $rank += 10;
    $this->cols['unit'] = array(
        'rank' => $rank,
        'width' => 11,
        'status' => false,
        'title' => array(
            'textkey' => 'Unit'
        ),
        'border-left' => true,
    );
    if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
        $this->cols['unit']['status'] = true;
    }

    $rank += 10;
    $this->cols['discount'] = array(
        'rank' => $rank,
        'width' => 13,
        'status' => false,
        'title' => array(
            'textkey' => 'ReductionShort'
        ),
        'border-left' => true,
    );
    if ($this->atleastonediscount) {
        $this->cols['discount']['status'] = true;
    }

    $rank += 1000;
 $this->cols['totalexcltax'] = array(
        'rank' => $rank,
        'width' => 26,
        'status' => !getDolGlobalString('PDF_ORDER_HIDE_PRICE_EXCL_TAX'),
        'title' => array(
            'textkey' => 'TotalHTShort'
        ),
        'content' => array(
            'align' => 'C',
        ),
        'border-left' => true,
    );

    $rank += 1010;
    $this->cols['totalincltax'] = array(
        'rank' => $rank,
        'width' => 26,
        'status' => getDolGlobalBool('PDF_ORDER_SHOW_PRICE_INCL_TAX'),
        'title' => array(
            'textkey' => 'TotalTTCShort'
        ),
        'border-left' => true,
    );

    // Add extrafields cols
    if (!empty($object->lines)) {
        $line = reset($object->lines);
        $this->defineColumnExtrafield($line, $outputlangs, $hidedetails);
    }

    // Check if any line has a serial number in llx_product_lot
  // Check for serial numbers in llx_product_lot or llx_product_batch, and garantie in llx_product_extrafields
    $atleastoneserialnumber = false;
    $atleastonegarantie = false;
    $fk_warehouse = !empty($object->fk_warehouse) ? $object->fk_warehouse : 0;
    foreach ($object->lines as $line) {
        if (!empty($line->fk_product)) {
            // Check llx_product_batch if warehouse is set
            if ($fk_warehouse > 0) {
                $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_batch pb";
                $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock ps ON pb.fk_product_stock = ps.rowid";
                $sql .= " WHERE ps.fk_product = ".((int) $line->fk_product);
                $sql .= " AND ps.fk_entrepot = ".((int) $fk_warehouse);
                $resql = $this->db->query($sql);
                if ($resql) {
                    $obj = $this->db->fetch_object($resql);
                    if ($obj && $obj->nb > 0) {
                        $atleastoneserialnumber = true;
                    }
                }
            }
            // Check llx_product_lot
            $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_lot";
            $sql .= " WHERE fk_product = ".((int) $line->fk_product);
            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                if ($obj && $obj->nb > 0) {
                    $atleastoneserialnumber = true;
                }
            }
           // Check llx_product_extrafields for garantie
$sql = "SELECT garantie FROM ".MAIN_DB_PREFIX."product_extrafields";
$sql .= " WHERE fk_object = ".((int) $line->fk_product);
$resql = $this->db->query($sql);
if ($resql) {
    $obj = $this->db->fetch_object($resql);
    if ($obj && !empty($obj->garantie)) {
        $atleastonegarantie = true;
    }
}
        }
    }
    if (isset($this->cols['serialnumber'])) {
        $this->cols['serialnumber']['status'] = $atleastoneserialnumber;
    }
    if (isset($this->cols['garantie'])) {
        $this->cols['garantie']['status'] = $atleastonegarantie;
    }

    $parameters = array(
        'object' => $object,
        'outputlangs' => $outputlangs,
        'hidedetails' => $hidedetails,
        'hidedesc' => $hidedesc,
        'hideref' => $hideref
    );

    $reshook = $hookmanager->executeHooks('defineColumnField', $parameters, $this);
    if ($reshook < 0) {
        setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
    } elseif (empty($reshook)) {
        $this->cols = array_replace($this->cols, $hookmanager->resArray);
    } else {
        $this->cols = $hookmanager->resArray;
    }
}

/**
 * Get the serial number for an order line
 *
 * @param object $object The order object
 * @param int $i The line index
 * @return string The serial number or empty string if not found
 */
/**
 * Get the serial number for an order line
 *
 * @param object $object The order object
 * @param int $i The line index
 * @return string The serial number or empty string if not found
 */
/**
 * Get the serial number(s) for an order line
 *
 * @param object $object The order object
 * @param int $i The line index
 * @return string The serial numbers (comma-separated) or empty string if not found
 */
protected function getLineSerialNumber($object, $i)
{
    $serialnumbers = array();
    $qty = (int) $object->lines[$i]->qty; // Get the quantity ordered
    
    if ($qty > 0) {
        
        // DEBUG: Log what we're working with
        error_log("DEBUG - Invoice ID: " . ($object->id ?? 'NULL'));
        error_log("DEBUG - Line index: " . $i);
        error_log("DEBUG - Invoice line rowid: " . ($object->lines[$i]->rowid ?? 'NULL'));
        error_log("DEBUG - Product ID: " . ($object->lines[$i]->fk_product ?? 'NULL'));
        
        // Method 1: Find expedition via order link (Invoice Line -> Order Line -> Expedition Detail)
        if (!empty($object->lines[$i]->rowid)) {
            
            // First, find the order line that this invoice line is based on
            $order_line_id = null;
            
            // Check if invoice line has direct link to order line (fk_parent_line or similar)
            if (!empty($object->lines[$i]->fk_parent_line)) {
                $order_line_id = $object->lines[$i]->fk_parent_line;
                error_log("DEBUG - Direct order line link: " . $order_line_id);
            } else {
                // Look for order line via facturedet_rec or similar linking table
                // Or find via product and customer match
                
                $sql = "SELECT cd.rowid as order_line_id";
                $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
                $sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande c ON cd.fk_commande = c.rowid";
                $sql .= " WHERE c.fk_soc = ".((int) $object->socid);
                
                if (!empty($object->lines[$i]->fk_product)) {
                    $sql .= " AND cd.fk_product = ".((int) $object->lines[$i]->fk_product);
                }
                
                // Add date range to find recent orders
                if (!empty($object->date)) {
                    $sql .= " AND c.date_commande >= DATE_SUB('".date('Y-m-d', $object->date)."', INTERVAL 60 DAY)";
                    $sql .= " AND c.date_commande <= '".date('Y-m-d', $object->date)."'";
                }
                
                $sql .= " ORDER BY c.date_commande DESC, cd.rowid DESC";
                $sql .= " LIMIT 1";
                
                error_log("DEBUG - Looking for order line: " . $sql);
                
                $resql = $this->db->query($sql);
                if ($resql) {
                    $obj = $this->db->fetch_object($resql);
                    if ($obj) {
                        $order_line_id = $obj->order_line_id;
                        error_log("DEBUG - Found order line ID: " . $order_line_id);
                    }
                    $this->db->free($resql);
                }
            }
            
            // Now find expedition detail linked to this order line
            if ($order_line_id) {
                $sql = "SELECT ed.rowid as expeditiondet_id";
                $sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet ed";
                $sql .= " WHERE ed.element_type = 'commande'";
                $sql .= " AND ed.fk_elementdet = ".((int) $order_line_id);
                
                error_log("DEBUG - Looking for expedition detail: " . $sql);
                
                $resql = $this->db->query($sql);
                $expeditiondet_id = null;
                
                if ($resql) {
                    $obj = $this->db->fetch_object($resql);
                    if ($obj) {
                        $expeditiondet_id = $obj->expeditiondet_id;
                        error_log("DEBUG - Found expedition detail ID: " . $expeditiondet_id);
                    }
                    $this->db->free($resql);
                }
                
                // Get batches for this expedition detail
                if ($expeditiondet_id) {
                    $sql = "SELECT eb.batch";
                    $sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet_batch eb";
                    $sql .= " WHERE eb.fk_expeditiondet = ".((int) $expeditiondet_id);
                    $sql .= " AND eb.batch IS NOT NULL AND eb.batch != ''";
                    $sql .= " ORDER BY eb.rowid";
                    
                    error_log("DEBUG - Getting batches: " . $sql);
                    
                    $resql = $this->db->query($sql);
                    if ($resql) {
                        while ($obj = $this->db->fetch_object($resql)) {
                            if (!empty($obj->batch)) {
                                $serialnumbers[] = $obj->batch;
                                error_log("DEBUG - Found batch: " . $obj->batch);
                            }
                        }
                        $this->db->free($resql);
                    }
                }
            }
        }
        
        // Method 2: Direct search by customer and product if no order link found
        if (empty($serialnumbers) && !empty($object->socid)) {
            
            $sql = "SELECT eb.batch";
            $sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet_batch eb";
            $sql .= " INNER JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON eb.fk_expeditiondet = ed.rowid";
            $sql .= " INNER JOIN ".MAIN_DB_PREFIX."expedition e ON ed.fk_expedition = e.rowid";
            $sql .= " WHERE e.fk_soc = ".((int) $object->socid);
            
            if (!empty($object->lines[$i]->fk_product)) {
                // Since ed.fk_product might be NULL, also check the order line
                $sql .= " AND (ed.fk_product = ".((int) $object->lines[$i]->fk_product);
                $sql .= " OR EXISTS (";
                $sql .= "   SELECT 1 FROM ".MAIN_DB_PREFIX."commandedet cd";
                $sql .= "   WHERE cd.rowid = ed.fk_elementdet";
                $sql .= "   AND cd.fk_product = ".((int) $object->lines[$i]->fk_product);
                $sql .= " ))";
            }
            
            $sql .= " AND eb.batch IS NOT NULL AND eb.batch != ''";
            
            // Add date filter
            if (!empty($object->date)) {
                $sql .= " AND e.date_expedition >= DATE_SUB('".date('Y-m-d', $object->date)."', INTERVAL 30 DAY)";
                $sql .= " AND e.date_expedition <= DATE_ADD('".date('Y-m-d', $object->date)."', INTERVAL 30 DAY)";
            }
            
            $sql .= " ORDER BY e.date_expedition DESC, eb.rowid";
            $sql .= " LIMIT ".((int) $qty);
            
            error_log("DEBUG - Fallback search: " . $sql);
            
            $resql = $this->db->query($sql);
            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    if (!empty($obj->batch)) {
                        $serialnumbers[] = $obj->batch;
                        error_log("DEBUG - Found batch via fallback: " . $obj->batch);
                    }
                }
                $this->db->free($resql);
            }
        }
        
        error_log("DEBUG - Final result: " . implode(', ', $serialnumbers));
    }
    
    // Return comma-separated serial numbers or empty string
    return !empty($serialnumbers) ? implode(', ', $serialnumbers) : '';
}


/**
 * Get the warranty period label for an order line
 *
 * @param object $object The order object
 * @param int $i The line index
 * @return string The warranty period label (e.g., "1 MOIS") or empty string if not found or invalid
 */
protected function getLineGarantie($object, $i)
{
 // Define the mapping of garantie values to labels
$garantieMap = [
    '1' => '0 MOIS -3 jours-',
    '2' => '1 MOIS',
    '3' => '3 MOIS',
    '4' => '6 MOIS',
    '5' => '12 MOIS'
];


    if (!empty($object->lines[$i]->fk_product)) {
        // Fetch the garantie value from llx_product_extrafields
        $sql = "SELECT garantie FROM ".MAIN_DB_PREFIX."product_extrafields";
        $sql .= " WHERE fk_object = ".((int) $object->lines[$i]->fk_product);
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj && !empty($obj->garantie)) {
                // Log the input and output for debugging
                $result = isset($garantieMap[$obj->garantie]) ? $garantieMap[$obj->garantie] : '';
                dol_syslog("getLineGarantie: Product ID=".$object->lines[$i]->fk_product.", Garantie=".$obj->garantie.", Result=".$result, LOG_DEBUG);
                // Return the mapped label or empty string if not found
                return $result;
            }
        } else {
            dol_syslog("getLineGarantie: SQL Error=".$this->db->lasterror(), LOG_ERR);
        }
    }
    dol_syslog("getLineGarantie: No garantie for Product ID=".($object->lines[$i]->fk_product ?? 'N/A'), LOG_DEBUG);
    return '';
}


protected function printCustomDescContent($pdf, $curY, $colKey, $object, $i, $outputlangs, $hideref = 0, $hidedesc = 0)
{
    if ($hidedesc) {
        return;
    }

    $default_font_size = pdf_getPDFFontSize($outputlangs);
    $pdf->SetFont('', '', $default_font_size - 1);

    // Use only the product label (description or libelle)
    $desc = $object->lines[$i]->desc ? $object->lines[$i]->desc : $object->lines[$i]->libelle;
    $desc = dol_htmlentitiesbr($desc);

    // Get column position and width
    $xstart = $this->getColumnContentXStart($colKey);
    $width = $this->cols[$colKey]['content']['width'] ?? $this->cols[$colKey]['width'];

    // Write description
    $pdf->writeHTMLCell($width, 2, $xstart, $curY, $desc, 0, 0, false, true, $this->cols[$colKey]['content']['align'] ?? 'L');
}
}

