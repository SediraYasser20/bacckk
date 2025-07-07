<?php
// Trigger pour envoi automatique d'e-mail après validation d'une expédition

include_once DOL_DOCUMENT_ROOT .'/core/class/interfaces/interfaces.class.php';
include_once DOL_DOCUMENT_ROOT .'/core/class/CMailFile.class.php';

class Interface_99_modExpeditionEmail_Trigger extends InterfaceTrigger
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = 'ExpeditionEmailTrigger';
        $this->family = 'interface';
        $this->description = 'Trigger to send email automatically after expedition validation';
        $this->version = 'development'; // ou 'dolibarr' si intégré رسمياً
        $this->picto = 'email';
    }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (($action === 'SHIPPING_VALIDATE' || $action === 'DELIVERY_VALIDATE') && !empty($object->id)) {
            dol_syslog("ExpeditionEmailTrigger: Trigger for action $action");

            // تحميل بيانات الطرف الثالث
            if (!is_object($object->thirdparty)) {
                $object->fetch_thirdparty();
            }

            // توليد PDF
            $modelpdf = !empty($conf->global->EXPEDITION_ADDON_PDF) ? $conf->global->EXPEDITION_ADDON_PDF : 'standard';
            $result = $object->generateDocument($modelpdf, $langs);

            if ($result <= 0) {
                dol_syslog("ExpeditionEmailTrigger: Failed to generate PDF", LOG_ERR);
                return -1;
            }

            // تحديد المسار الكامل
            $file = $conf->expedition->dir_output.'/sending/'.$object->ref.'/'.$object->ref.'.pdf';
            if (!file_exists($file)) {
                dol_syslog("ExpeditionEmailTrigger: PDF not found at $file", LOG_ERR);
                return -1;
            }

            // بيانات البريد
            $to = $object->thirdparty->email;
            $toname = $object->thirdparty->name;

            if (empty($to)) {
                dol_syslog("ExpeditionEmailTrigger: Thirdparty email is empty", LOG_WARNING);
                return 0;
            }

            $from = !empty($conf->global->MAIN_MAIL_EMAIL_FROM) ? $conf->global->MAIN_MAIL_EMAIL_FROM : 'noreply@localhost';

            $subject = '['.$conf->global->MAIN_INFO_SOCIETE_NOM.'] '.$langs->trans("DeliveryFollow").' #'.$object->ref;
            $message = "Bonjour $toname,\n\nVotre expédition ".$object->ref." a été validée. Veuillez trouver le bon d'expédition en pièce jointe.\n\nMerci.";

            $mail = new CMailFile($subject, $to, $from, $message, array($file));

            if (!$mail->sendfile()) {
                dol_syslog("ExpeditionEmailTrigger: Mail failed - ".$mail->error, LOG_ERR);
                return -1;
            }

            dol_syslog("ExpeditionEmailTrigger: Mail sent to $to");
            return 1;
        }

        return 0;
    }
}

