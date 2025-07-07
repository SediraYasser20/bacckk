<?php
// File: custom/savtab/core/modules/modSavTab.class.php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Class modSavTab
 * Module descriptor for adding an SAV tab to sales orders.
 */
class modSavTab extends DolibarrModules
{
    /**
     * Constructor.
     * @param DoliDB $db
     */
    public function __construct(DoliDB &$db)
    {
        // ---- FIX: Added call to parent constructor. This is CRITICAL for hooks to be registered.
        parent::__construct($db);

        $this->numero         = 104000; // Unique module ID
        $this->rights_class   = 'savtab';
        $this->family         = 'tools';
        $this->name           = 'savtab';
        $this->description    = 'Adds a SAV tab on the Order card';
        $this->version        = '1.0.0';
        $this->const_name     = 'MAIN_MODULE_SAVTAB';
        $this->picto          = 'service';

        // Data directories to create when module is enabled
        $this->dirs           = array('savtab/temp');

        // Dependencies
        $this->depends        = array();
        $this->required_by    = array();

        // Constants (none)
        $this->const          = array();

        // ---- This line is correct. The constructor fix makes it work.
        $this->hooks          = array('ordercard' => array('completeTabsHead'));

        // Module parts (none)
        $this->module_parts   = array();

        // Permissions (none)
        $this->rights         = array();

        // Menus (none)
        $this->menu           = array();

        // Export code (none)
        $this->export_code    = array();

        // Cronjobs (none)
        $this->cronjobs       = array();

        // Config pages
        $this->config_page_url = array();

        // Language files
        $this->langfiles      = array('savtab@savtab');
    }

    /**
     * Enable module and set up extra fields.
     * @param string $options Options when enabling module
     * @return int            1 on success, 0 on failure
     */
    public function init($options = '')
    {
        // ---- FIX: Call parent init for standard activation procedures.
        return parent::init($options);
    }

    /**
     * Disable module.
     * @param string $options Options when disabling module
     * @return int            1 on success
     */
    public function remove($options = '')
    {
        // ---- FIX: Call parent remove for standard deactivation procedures.
        return parent::remove($options);
    }
}