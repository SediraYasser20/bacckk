<?php
include_once DOL_DOCUMENT_ROOT .'/core/modules/DolibarrModules.class.php';

class modExpeditionEmail extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs;

        $this->db = $db;
        $this->numero = 999000; // تأكد من رقم فريد
        $this->rights_class = 'expeditionemail';

        $this->family = "crm";
        $this->name = "ExpeditionEmail";
        $this->description = "Send automatic emails on expedition validation";
        $this->version = '1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

        $this->module_parts = array(
            'triggers' => 1, // هذا هو المهم لتحميل التريغرات
        );

        $this->dirs = array("/expeditionemail/temp");

        $this->config_page_url = array("expeditionemail_setup.php@expeditionemail");

        // اسم التريغر
        $this->langfiles = array("expeditionemail@expeditionemail");
        $this->picto = 'email';

        $this->enable = '1';
    }
}

