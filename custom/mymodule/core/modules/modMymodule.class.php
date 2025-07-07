<?php
/* Copyright (C) 2004-2018 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019 Nicolas ZABOURI <info@inovea-conseil.com>
 * Copyright (C) 2019-2024 FrÃ©dÃ©ric France <frederic.france@free.fr>
 * Copyright (C) 2025 SuperAdmin
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
 * \defgroup mymodule Module Mymodule
 * \brief Mymodule module descriptor.
 *
 * \file htdocs/mymodule/core/modules/modMymodule.class.php
 * \ingroup mymodule
 * \brief Description and activation file for module Mymodule
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Mymodule
 */
class modMymodule extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;

        // Id for module (must be unique).
        $this->numero = 500000; // TODO: Reserve an ID at https://wiki.dolibarr.org/index.php/List_of_modules_id

        // Key text used to identify module (for permissions, menus, etc...)
        $this->rights_class = 'mymodule';

        // Family can be 'base' (core modules), 'crm', 'financial', 'hr', 'projects', 'products', 'ecm', 'technic', 'interface', 'other', etc.
        $this->family = "other";

        // Module position in the family on 2 digits ('01', '10', '20', ...)
        $this->module_position = '900';

        // Module label (no space allowed), used if translation string 'ModuleMymoduleName' not found
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        // Module description, used if translation string 'ModuleMymoduleDesc' not found
        $this->description = "MymoduleDescription";
        $this->descriptionlong = "MymoduleDescription";

        // Author
        $this->editor_name = 'Informatics-dz';
        $this->editor_url = '';
        $this->editor_squarred_logo = '';

        // Version: 'development', 'experimental', 'dolibarr', or version string like 'x.y.z'
        $this->version = '1.0';

        // Key used in llx_const table to save module status
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

        // Picto: Use 'fa-xxx' for Font Awesome or 'pictovalue@module' for module-specific image
        $this->picto = 'fa-file';

        // Define module features
        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 1, // Enable custom models
            'printing' => 0,
            'theme' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array(),
            'moduleforexternal' => 0,
            'websitetemplates' => 0,
            'captcha' => 0
        );

        // Data directories to create
        $this->dirs = array("/mymodule/temp");

        // Config pages
        $this->config_page_url = array("setup.php@mymodule");

        // Dependencies
        $this->hidden = getDolGlobalInt('MODULE_MYMODULE_DISABLED');
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();

        // Language files
        $this->langfiles = array("mymodule@mymodule");

        // Prerequisites
        $this->phpmin = array(7, 1);
        $this->need_dolibarr_version = array(19, -3);
        $this->need_javascript_ajax = 0;

        // Messages at activation
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // Constants
        $this->const = array();

        if (!isModEnabled("mymodule")) {
            $conf->mymodule = new stdClass();
            $conf->mymodule->enabled = 0;
        }

        // Tabs
        $this->tabs = array();

        // Dictionaries
        $this->dictionaries = array();

        // Boxes/Widgets
        $this->boxes = array();

        // Cronjobs
        $this->cronjobs = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        // Main menu entries
        $this->menu = array();
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu' => '',
            'type' => 'top',
            'titre' => 'ModuleMymoduleName',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
            'mainmenu' => 'mymodule',
            'leftmenu' => '',
            'url' => '/mymodule/mymoduleindex.php',
            'langs' => 'mymodule@mymodule',
            'position' => 1000 + $r,
            'enabled' => '0',
            'perms' => '1',
            'target' => '',
            'user' => 2,
        );
    }

    /**
     * Function called when module is enabled.
     * Adds constants, boxes, permissions, menus, and document templates to Dolibarr database.
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, <=0 if KO
     */
    public function init($options = '')
    {
        global $conf, $langs;

        // Load module SQL tables
        $result = $this->_load_tables('/mymodule/sql/');
        if ($result < 0) {
            return -1;
        }

        // Remove permissions and default entries
        $this->remove($options);

        $sql = array();

        // Document templates
        $moduledir = dol_sanitizeFileName('mymodule');
        $myTmpObjects = array(
            'Commande' => array('includerefgeneration' => 0, 'includedocgeneration' => 1), // Sales order templates
            'Facture' => array('includerefgeneration' => 0, 'includedocgeneration' => 1),  // Invoice templates
            'Mo' => array('includerefgeneration' => 0, 'includedocgeneration' => 1) // MO templates
        );

        foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
            if ($myTmpObjectArray['includedocgeneration']) {
                $type = strtolower($myTmpObjectKey); // 'commande', 'facture', or 'mo'
                $template_dir = ($type == 'facture') ? 'invoices' : (($type == 'commande') ? 'orders' : 'mrp');
                $src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_'.$template_dir.'.odt';
                $dirodt = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/doctemplates/'.$moduledir;
                $dest = $dirodt.'/template_'.$template_dir.'.odt';

                if (file_exists($src) && !file_exists($dest)) {
                    require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
                    dol_mkdir($dirodt);
                    $result = dol_copy($src, $dest, '0', 0);
                    if ($result < 0) {
                        $langs->load("errors");
                        $this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
                        return 0;
                    }
                }

                // Register document models
                $models = ($type == 'facture') ?
                    array('bon_garantie' => 'invoice') : // Invoice template
                    (($type == 'commande') ?
                    array('bon_livraison' => 'order') : // Sales order template
                    (($type == 'mo') ?
                    array('pdf_mo_serial_label' => 'mo') : // MO template
                    array()));

                foreach ($models as $model_name => $model_type) {
                    $sql = array_merge($sql, array(
                        "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = '".$this->db->escape($model_name)."' AND type = '".$this->db->escape($model_type)."' AND entity = ".((int) $conf->entity),
                        "INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, entity) VALUES('".$this->db->escape($model_name)."', '".$this->db->escape($model_type)."', ".((int) $conf->entity).")"
                    ));
                }
            }
        }

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Removes constants, boxes, permissions, and document models from Dolibarr database.
     *
     * @param string $options Options when disabling module ('', 'noboxes')
     * @return int 1 if OK, <=0 if KO
     */
    public function remove($options = '')
    {
        $sql = array(
            // Remove sales order template
            "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'bon_livraison' AND type = 'order' AND entity = ".((int) $conf->entity),
            // Remove invoice template
            "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'bon_garantie' AND type = 'invoice' AND entity = ".((int) $conf->entity),
            // Remove MO serial label template
            "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'pdf_mo_serial_label' AND type = 'mo' AND entity = ".((int) $conf->entity)
        );
        return $this->_remove($sql, $options);
    }
}
