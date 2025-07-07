<?php 
dol_include_once('/core/lib/admin.lib.php');
require_once DOL_DOCUMENT_ROOT.'/user/class/userbankaccount.class.php';
class savorders
{

    // SAV Status
    const RECIEVED_CUSTOMER = 1;
    const DELIVERED_CUSTOMER = 2;

    const DELIVERED_SUPPLIER = 1;
    const RECEIVED_SUPPLIER = 2;

    public function __construct($db)
    {   
        global $langs;

        $this->db = $db;
    }

    /**
     * get Total HT & Remise
     * @param type $object
     * @return boolean
     */
    public function getTotalFromLine(&$object)
    {
        global $conf;

        $array = []; $total = 0; $remise = 0;
        
        foreach ($object->lines as $line)
        {
            if ($line->product_type == 0) {
                $tot = $line->subprice*$line->qty;
                $total += $tot;
            }
            elseif ($line->product_type == 720801 || $line->product_type == 720802 || $line->product_type == $conf->global->ADDREMISE_PRODUCT_TYPE || $line->product_type == ($conf->global->ADDREMISE_PRODUCT_TYPE + 1)) {
                $remise += abs($line->total_ht);
            }
        }

        $array['total'] = $total;
        $array['remise'] = $remise;

        return $array;
    }
    
    public function upgradeTheModule()
    {
        global $conf, $langs;

        dol_include_once('/savorders/core/modules/modsavorders.class.php');
        $modsavorders = new modsavorders($this->db);

        $lastversion    = $modsavorders->version;
        $currentversion = dolibarr_get_const($this->db, 'SAVORDERS_LAST_VERSION_OF_MODULE', 0);

        if (!$currentversion || ($currentversion && $lastversion != $currentversion)){
            $res = $this->initsavordersModule($lastversion);
            if($res)
                dolibarr_set_const($this->db, 'SAVORDERS_LAST_VERSION_OF_MODULE', $lastversion, 'chaine', 0, '', 0);
            return 1;
        }
        return 0;
    }
    
    public function initsavordersModule($lastversion = '')
    {
        global $conf, $langs;

        $langs->load('savorders@savorders');

        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);

        $position = 10;

        $list = 1;
        
        // --------------------------------------------------------- Commandes Clients
        // SAV order?
        $result = $extrafields->addExtraField('savorders_sav', $langs->trans('savorders_itsav'), "boolean", $position++, '', 'commande',  0, 0, '', '', 0, '', $list);
        
        // SAV Status
        $arrp = array($this::RECIEVED_CUSTOMER => $langs->trans('ProductReceivedFromCustomer'), $this::DELIVERED_CUSTOMER => $langs->trans('ProductDeliveredToCustomer'));
        $param = serialize(array('options' => $arrp));
        $extrafields->addExtraField('savorders_status', $langs->trans('savorders_status'), "select", $position++, '', "commande", 0, 0, '', $param, 0, '', $list); 

        // SAV History
        $result = $extrafields->addExtraField('savorders_history', $langs->trans('savorders_history'), "text", $position++, '', 'commande',  0, 0, '', '', 0, '', $list);


        // --------------------------------------------------------- Commandes Fournisseurs
        // SAV order?
        $result = $extrafields->addExtraField('savorders_sav', $langs->trans('savorders_itsav'), "boolean", $position++, '', 'commande_fournisseur',  0, 0, '', '', 0, '', $list);
        

        // SAV Status
        $arrp = array($this::DELIVERED_SUPPLIER => $langs->trans('ProductDeliveredToSupplier'), $this::RECEIVED_SUPPLIER => $langs->trans('ProductReceivedFromSupplier'));
        $param = serialize(array('options' => $arrp));
        $extrafields->addExtraField('savorders_status', $langs->trans('savorders_status'), "select", $position++, '', "commande_fournisseur", 0, 0, '', $param, 0, '', $list); 

        // SAV History
        $result = $extrafields->addExtraField('savorders_history', $langs->trans('savorders_history'), "text", $position++, '', 'commande_fournisseur',  0, 0, '', '', 0, '', $list);

        return 1;
    }
    
    public function generateReceptionDocument($action = '', $commande = '')
    {
        
    }

}
?>