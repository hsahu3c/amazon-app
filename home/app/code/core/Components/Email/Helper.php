<?php

namespace App\Core\Components\Email;

/**
 * Helper class for mail template
 */
class Helper
{
    /**
     * function return the uninstall mail data from marketplace
     *
     * @param array $data
     * @return array
     */
    public function getUninstallMailDataFromMarketplace($data)
    {
        return $this->getMailDataFromMarketplace($data['templateSource'], 'getUninstallMailData');
    }

    /**
     * function return the otp mail data from marketplace
     *
     * @param array $data
     * @return array
     */
    public function getOtpMailDataFromMarketplace($data)
    {
        return $this->getMailDataFromMarketplace($data['templateSource'], 'getOtpMailData');
    }

    /**
     * function return the account block mail data from marketplace
     *
     * @param array $data
     * @return array
     */
    public function getBlockMailDataFromMarketplace($data)
    {
        return $this->getMailDataFromMarketplace($data['marketplace'], 'accountBlockMailData');
    }

    /**
     * function return the account deactivate mail data from marketplace
     *
     * @param array $data
     * @return array
     */
    public function getDeactivateMailDataFromMarketplace($data)
    {
        return $this->getMailDataFromMarketplace($data['marketplace'], 'accountDeactivateMailData');
    }

    /**
     * function return the reset password mail data from marketplace
     *
     * @param array $data
     * @return array
     */
    public function getResetPasswordDataFromMarketplace($data)
    {
        return $this->getMailDataFromMarketplace($data['templateSource'], 'getResetPasswordMailData');
    }

    /**
     * function get the marketplace data for the specified mail
     *
     * @param string $marketplace
     * @param string $functionName
     * @return array
     */
    private function getMailDataFromMarketplace($marketplace, $functionName)
    {
        $emailData = [];
        if ($marketplace && $functionName) {
            $emailTemplateClass = 'App\\' . ucfirst($marketplace) . '\\Components\Email\EmailTemplate';
            if (class_exists($emailTemplateClass) && method_exists($emailTemplateClass, $functionName)) {
                $emailtemplate = $this->di->getObjectManager()->get($emailTemplateClass);
                $emailData = $emailtemplate->$functionName();
            }
        }
        return $emailData;
    }
}
