<?php
// Load Dolibarr environment
$path_to_main_inc = __DIR__ . '/../main.inc.php'; // Adjust path if needed
if (!file_exists($path_to_main_inc)) {
    die("Error: main.inc.php not found. Please check the path.");
}
require_once $path_to_main_inc;

// Include Product class
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

// Check if Dolibarr is loaded
if (empty($conf) || empty($db)) {
    die("Error: Dolibarr environment not loaded.");
}

// Check user login
if (empty($user->login)) {
    die("Please log in to Dolibarr first.");
}

// Enable error reporting, suppress deprecated warnings for TCPDI
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);

// Get form parameters
$action = GETPOST('action', 'alpha');
$product_id = GETPOST('product_id', 'int');
$lot_id = GETPOST('lot_id', 'int');
$quantity = GETPOST('quantity', 'int') ?: 1; // Default to 1

// Handle PDF generation (only for Generate Labels button)
if ($action == 'generate_labels' && $product_id && $lot_id && $quantity > 0) {
    // Load product
    $product = new Product($db);
    if ($product->fetch($product_id) <= 0) {
        die("Error: Product not found (ID: $product_id).");
    }

    // Fetch lot details
    $sql = "SELECT batch FROM " . MAIN_DB_PREFIX . "product_batch WHERE rowid = " . intval($lot_id);
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $lot = $db->fetch_object($resql)->batch;
    } else {
        die("Error: Lot not found (ID: $lot_id).");
    }

    // Use Dolibarr's PDF generator
  require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
try {
    // Initialize PDF with 40 mm Ã— 20 mm page size, landscape orientation
    $pdf = pdf_getInstance(array(40, 20), 'mm', 'L');
    
    // Set margins: 1 mm left/right, 2 mm top/bottom
    $marginH = 1;
    $marginV = 2;
    $pdf->SetMargins($marginH, $marginV, $marginH);
    
    // Disable auto page breaks and headers/footers
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Generate one page per label
    for ($i = 0; $i < $quantity; $i++) {
        $pdf->AddPage();
        
        // Page dimensions
        $pageW = 40; // 40 mm
        $pageH = 20; // 20 mm
        $printW = $pageW - 2 * $marginH; // 38 mm
        $printH = $pageH - 2 * $marginV; // 16 mm
        
        // Barcode dimensions (adjust to fit within printable area)
        $barcodeW = 30; // 30 mm wide barcode
        $barcodeH = 10; // 10 mm high barcode
        
        // Center barcode horizontally and vertically
        $posX = $marginH + ($printW - $barcodeW) / 2; // â‰ˆ 4 mm
        $posY = $marginV + ($printH - $barcodeH) / 2; // â‰ˆ 5 mm
        
        // Generate Code 128 barcode
        $pdf->write1DBarcode($lot, 'C128', $posX, $posY, $barcodeW, $barcodeH, 0.4, array('text' => true, 'font' => 'helvetica', 'fontsize' => 6), '');
    }
    
    // Output PDF
    $filename = 'labels_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $lot) . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
    } catch (Exception $e) {
        die("PDF Generation Error: " . $e->getMessage());
    }
}

// Start HTML output
llxHeader('', 'Label Generator');
print_fiche_titre('Label Generator');

?>
<style>
    .label-form div { margin-bottom: 10px; }
    .label-form label { display: inline-block; width: 180px; }
    .label-form select, .label-form input[type="number"] { min-width: 250px; }
    .error { color: red; }
    .debug { color: blue; }
</style>

<!-- Form for product selection (to refresh lots) -->
<form action="<?php echo $_SERVER["PHP_SELF"]; ?>" method="POST" class="label-form">
    <input type="hidden" name="token" value="<?php echo newToken(); ?>">
    <input type="hidden" name="action" value="select_product">

    <div>
        <label for="product_id">Select Product:</label>
        <select name="product_id" id="product_id" onchange="this.form.submit()" required>
            <option value="">-- Select a Product --</option>
            <?php
            $sql = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product WHERE tosell = 1";
            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $selected = ($product_id == $obj->rowid) ? 'selected' : '';
                    echo '<option value="' . $obj->rowid . '" ' . $selected . '>' . $obj->ref . ' - ' . $obj->label . '</option>';
                }
                $db->free($resql);
            } else {
                echo '<option value="">Error loading products: ' . $db->error() . '</option>';
            }
            ?>
        </select>
    </div>
</form>

<!-- Form for generating labels -->
<form action="<?php echo $_SERVER["PHP_SELF"]; ?>" method="POST" class="label-form">
    <input type="hidden" name="token" value="<?php echo newToken(); ?>">
    <input type="hidden" name="action" value="generate_labels">
    <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

    <div>
        <label for="lot_id">Select Lot:</label>
        <select name="lot_id" id="lot_id" required>
            <option value="">-- Select a Lot --</option>
            <?php
            if ($product_id) {
                $sql = "SELECT pb.rowid, pb.batch FROM " . MAIN_DB_PREFIX . "product_batch pb";
                $sql .= " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON pb.fk_product_stock = ps.rowid";
                $sql .= " WHERE ps.fk_product = " . intval($product_id);
                $resql = $db->query($sql);
                if ($resql && $db->num_rows($resql) > 0) {
                    while ($obj = $db->fetch_object($resql)) {
                        $selected = ($lot_id == $obj->rowid) ? 'selected' : '';
                        echo '<option value="' . $obj->rowid . '" ' . $selected . '>' . $obj->batch . '</option>';
                    }
                    $db->free($resql);
                } else {
                    echo '<option value="">No lots available</option>';
                }
            }
            ?>
        </select>
    </div>

    <div>
        <label for="quantity">Number of Labels:</label>
        <input type="number" name="quantity" id="quantity" value="<?php echo $quantity; ?>" min="1" required>
    </div>

    <div>
        <input type="submit" value="Generate Labels" class="button">
    </div>
</form>

<?php
// Debug output
if ($action == 'generate_labels' && (!$product_id || !$lot_id || $quantity <= 0)) {
    print '<p class="error">Error: Please select a product, lot, and valid quantity.</p>';
}
print '<p class="debug">Debug: action = ' . htmlspecialchars($action) . ', product_id = ' . $product_id . ', lot_id = ' . $lot_id . ', quantity = ' . $quantity . '</p>';

llxFooter();
?>
