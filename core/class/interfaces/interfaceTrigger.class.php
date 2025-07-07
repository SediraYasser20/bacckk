<?php
/**
 *  \file       interfaceTrigger.class.php
 *  \brief      Abstract class for Dolibarr triggers
 */

abstract class InterfaceTrigger
{
    public $name;
    public $description;
    public $version;
    public $picto;

    /**
     * Function called when a Dolibarr business event is done.
     *
     * @param   string      $action     Type of action
     * @param   Object      $object     Object
     * @param   User        $user       Object user
     * @param   Translate   $langs      Object langs
     * @param   Conf        $conf       Object conf
     * @return  int                     <0 if KO, 0 if no action, >0 if OK
     */
    abstract public function runTrigger($action, $object, $user, $langs, $conf);
}
