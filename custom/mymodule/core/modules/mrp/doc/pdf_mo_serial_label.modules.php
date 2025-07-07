<?php
/* Copyright (C) 2025 SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    mymodule/core/modules/mrp/doc/pdf_mo_serial_label.modules.php
 * \ingroup mymodule
 * \brief   PDF template for printing serial number labels of produced products in MO
 */

dol_include_once('/mrp/class/mo.class.php');
dol_include_once('/core/modules/mrp/modules_mo.php');
dol_include_once('/core/lib/pdf.lib.php'); // Required for pdf_getInstance()

class pdf_mo_serial_label extends ModelePDFMo
{
    /**
     * @var array Page format in millimeters (width, height)
     */
    public $format = array(40, 20); // 40 mm wide × 20 mm high (landscape)

    public function __construct($db)
    {
        parent::__construct($db);
        $this->name = 'pdf_mo_serial_label';
        $this->description = 'PDF template for printing serial number labels of produced products in MO';
    }

    /**
     * Generates the PDF file with serial number labels for produced products
     *
     * @param Object $object The MO object
     * @param Object $outputlangs Language object
     * @param string $srctemplatepath Source template path
     * @param int $hidedetails Hide details flag
     * @param int $hidedesc Hide description flag
     * @param int $hideref Hide reference flag
     * @return int 1 if successful, 0 if error
     */
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $langs;

        if (!is_object($outputlangs)) {
            $outputlangs = $langs;
        }
        $outputlangs->load("mymodule");

        // Create PDF in landscape mode
        $pdf = pdf_getInstance($this->format, 'mm', 'L');

        // Margins: 1 mm left/right, 2 mm top/bottom
        $marginH = 1; // horizontal margins
        $marginV = 2; // vertical margins
        $pdf->SetMargins($marginH, $marginV, $marginH);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Smaller font size
        $fontSize = 6; // 6 pt for small labels
        $pdf->SetFont('helvetica', '', $fontSize);

        // Compute text height in mm (1 pt ≈ 0.35 mm)
        $textH = $fontSize * 0.35;

        // Fetch serial numbers of produced products only
        $sql = "SELECT DISTINCT mp.batch 
                FROM " . MAIN_DB_PREFIX . "mrp_production mp
                WHERE mp.fk_mo = " . ((int) $object->id) . "
                AND mp.role = 'produced' 
                AND mp.batch IS NOT NULL 
                AND mp.batch <> ''";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return 0;
        }

        // Check if there are any results
        if ($this->db->num_rows($resql) == 0) {
            $this->error = "No produced product serial numbers found for MO ID " . $object->id;
            $this->db->free($resql);
            return 0;
        }

        // Generate a page for each serial number
        while ($row = $this->db->fetch_object($resql)) {
            $pdf->AddPage();

            // Center text horizontally and vertically
            $pageW = $this->format[0];
            $pageH = $this->format[1];
            $printW = $pageW - 2 * $marginH;
            // Vertical center position
            $posY = max(0, ($pageH - $textH) / 2);
            $pdf->SetXY($marginH, $posY);
            $pdf->Cell($printW, $textH, $row->batch, 0, 1, 'C');
        }
        $this->db->free($resql);

        // Save PDF
        $dir = DOL_DATA_ROOT . '/mrp/' . dol_sanitizeFileName($object->ref);
        if (!file_exists($dir)) {
            dol_mkdir($dir);
        }
        $file = $dir . '/' . dol_sanitizeFileName($object->ref) . '_serial_labels.pdf';
        $pdf->Output($file, 'F');

        return 1;
    }
}
