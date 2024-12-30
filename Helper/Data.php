<?php

namespace Jvdh\ManitouSsoProcessing\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Jvdh\SamlExtendedAdvancedSettings\Helper\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Class Data
 *
 * Provides helper methods for handling configuration and encryption details
 * specifically related to the "Manitou" SSO (Single Sign-On) functionality.
 * It includes checking if the module is enabled, retrieving API keys, 
 * and fetching email addresses for notifications.
 */
class Data extends AbstractHelper
{
    /**
     * Constructor
     *
     * @param Context            $context   Core Magento context object
     * @param Config             $config    Custom config helper
     * @param EncryptorInterface $encryptor Magento encryption service
     */
    public function __construct(
        Context $context,
        protected Config $config,
        protected EncryptorInterface $encryptor
    ) {
        // Call the parent constructor for proper initialization
        parent::__construct($context);
    }

    /**
     * Check if the Manitou SSO module is enabled at the website level.
     *
     * @return bool  True if the module is enabled; false otherwise.
     */
    public function isModuleEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'foobar_saml_customer/advanced/enabled_for_manitou',
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    /**
     * Retrieve the Manitou API key from the configuration, and decrypt it
     * so it can be safely used within the code.
     *
     * @return string  The decrypted API key.
     */
    public function apiKey(): string
    {
        $apiKey = $this->scopeConfig->getValue(
            'foobar_saml_customer/advanced/manitou_api_key',
            ScopeInterface::SCOPE_WEBSITE
        );

        // Decrypt the value to get the original API key
        return $this->encryptor->decrypt($apiKey);
    }

    /**
     * Fetch the list of email addresses that should be copied on 
     * Manitou-related notifications. If multiple addresses are provided,
     * they will be returned as an array, otherwise as a single string.
     *
     * @return string|array  A string if only one email is configured,
     *                       or an array if multiple are present.
     */
    public function getCopyTo(): string|array
    {
        $value = $this->scopeConfig->getValue(
            'foobar_saml_customer/advanced/manitou_copy_to',
            ScopeInterface::SCOPE_WEBSITE
        );

        // If multiple emails are provided as a comma-separated string,
        // split them into an array. Otherwise, return the string.
        return str_contains((string) $value, ',')
            ? explode(',', (string) $value)
            : $value;
    }

    /**
     * Retrieve the "From" email address and sender name to be used
     * for Manitou-related emails. It looks up the configured store contact
     * email type, and then resolves the corresponding "trans_email" settings.
     *
     * @return array  An associative array with 'email' and 'name' keys.
     */
    public function getFromStore(): array
    {
        // Get the store identifier type (e.g., 'support', 'general', etc.)
        $type = $this->scopeConfig->getValue(
            'foobar_saml_customer/advanced/manitou_from',
            ScopeInterface::SCOPE_STORE
        );

        // Retrieve the email address configured under trans_email/ident_<type>/email
        $email = $this->scopeConfig->getValue(
            'trans_email/ident_' . $type . '/email',
            ScopeInterface::SCOPE_STORE
        );

        // Retrieve the name configured under trans_email/ident_<type>/name
        $name = $this->scopeConfig->getValue(
            'trans_email/ident_' . $type . '/name',
            ScopeInterface::SCOPE_STORE
        );

        return [
            'email' => $email,
            'name'  => $name
        ];
    }
}
