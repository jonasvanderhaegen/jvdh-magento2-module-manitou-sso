# `Jvdh\ManitouSsoProcessing\Plugin\Controller\Saml2\ACS.php`

Below is a plugin class that hooks into the `Foobar\SAML\Controller\Saml2\ACS` controller's `execute` method. It modifies the behavior of the Single Sign-On (SSO) response processing to do the following:

1. Validate whether the Manitou SSO module is enabled.  
2. Retrieve and decrypt an API key from the Magento configuration.  
3. Determine the correct customer group based on an external API lookup.  
4. Update the customer's group in Magento and adjust their cart/quote accordingly.  
5. Send an email notification and log the customer out if they fail group resolution.

```php
<?php

namespace Jvdh\ManitouSsoProcessing\Plugin\Controller\Saml2;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\Area;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Foobar\SAML\Controller\Saml2\ACS as FoobarACS;
use Jvdh\ManitouSsoProcessing\Helper\Data;

/**
 * Class ACS
 *
 * This plugin extends the behavior of the Foobar SAML ACS (Assertion Consumer Service) 
 * controller to:
 *  - Fetch trademarks data from an external API via Guzzle
 *  - Determine the appropriate customer group (GEHL, MANITOU, or BOTH) for the user
 *  - Update the customer's group in Magento accordingly
 *  - If resolution fails, send an "access denied" email and log the customer out
 */
class ACS
{
    /**
     * Manitou/Gehl Customer Group IDs for internal mapping.
     */
    private const GEHL = 30;
    private const MANITOU = 29;
    private const BOTH = 28;

    /**
     * Endpoint information for the external API call.
     */
    private const API_REQUEST_URI = 'https://x-api.xxx.com';
    private const API_REQUEST_ENDPOINT = '/secret/';

    /**
     * Constructor
     *
     * @param ResultFactory              $resultFactory
     * @param ManagerInterface           $messageManager
     * @param Session                    $checkoutSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param CartRepositoryInterface    $quoteRepository
     * @param Data                       $helper
     * @param ClientFactory              $clientFactory
     * @param ResponseFactory            $responseFactory
     * @param StoreManagerInterface      $storeManager
     * @param TransportBuilder           $transportBuilder
     * @param StateInterface             $inlineTranslation
     */
    public function __construct(
        protected ResultFactory $resultFactory,
        protected ManagerInterface $messageManager,
        protected Session $checkoutSession,
        protected CustomerRepositoryInterface $customerRepository,
        protected CartRepositoryInterface $quoteRepository,
        protected Data $helper,
        protected ClientFactory $clientFactory,
        protected ResponseFactory $responseFactory,
        protected StoreManagerInterface $storeManager,
        protected TransportBuilder $transportBuilder,
        protected StateInterface $inlineTranslation
    ) {
    }

    /**
     * Issues a GET request to the external API to retrieve trademark information.
     *
     * @param  string $cmdmids  Customer-specific ID used in the endpoint
     * @return Response|ResponseInterface
     */
    private function doRequest(string $cmdmids): Response|ResponseInterface
    {
        // Create a Guzzle client configured with the base URI
        $client = $this->clientFactory->create([
            'config' => ['base_uri' => self::API_REQUEST_URI]
        ]);

        // Prepare headers, including the decrypted API key
        $params = [
            'headers' => [
                'Key' => $this->helper->apiKey()  // Decrypted in Data helper
            ]
        ];

        try {
            // Construct the full endpoint URI
            $uriEndpoint = self::API_REQUEST_ENDPOINT . $cmdmids;

            // Perform the GET request and return the response
            return $client->request('GET', $uriEndpoint, $params);
        } catch (GuzzleException $guzzleException) {
            // If the request fails, return a Response object with the error info
            return $this->responseFactory->create([
                'status' => $guzzleException->getCode(),
                'reason' => $guzzleException->getMessage()
            ]);
        }
    }

    /**
     * Interprets the external API's response to decide which customer group 
     * the user should be placed in:
     *  - GEHL (ID 30)
     *  - MANITOU (ID 29)
     *  - BOTH (ID 28)
     * If multiple or zero trademarks are detected, it defaults to BOTH.
     *
     * @param  array $attributes  SAML attributes, expected to contain 'ids'
     * @return int|null           The group ID, or null if there's an error
     */
    private function resolveCustomerGroup(array $attributes): ?int
    {
        try {
            // Pull the first "id" from SAML attributes
            $response = $this->doRequest($attributes['ids'][0]);

            // Parse the body of the response
            $responseContent = $response->getBody()->getContents();
            $trademarks = json_decode($responseContent, true)['trademarks'];

            // If the trademark array doesn't contain exactly one item, default to BOTH
            if (sizeof($trademarks) !== 1) {
                return self::BOTH;
            }

            // Assign group based on the single trademark
            return ($trademarks[0] === 'Gehl') ? self::GEHL : self::MANITOU;
        } catch (\Exception) {
            // Return null if something unexpected happens
            return null;
        }
    }

    /**
     * Plugin method that runs after FoobarACS::execute().
     * This method checks if the Manitou SSO module is enabled; if so, it:
     *  - Processes the SAML response to extract attributes
     *  - Resolves the correct customer group
     *  - Updates the Magento customer group (and quote) accordingly
     *  - If resolution fails, sends an email and logs the customer out
     *
     * @param  FoobarACS $subject The original ACS controller
     * @param  mixed     $result  The result from the original execute() method
     * @return mixed
     */
    public function afterExecute(FoobarACS $subject, $result)
    {
        // If the module isn't enabled, return the original result
        if (!$this->helper->isModuleEnabled()) {
            return $result;
        }

        // Retrieve the current logged-in customer session
        $customerSession = $subject->_getCustomerSession();
        $customer = $customerSession->getCustomer();

        // Retrieve the Magento Customer model for read/write operations
        $customerModel = $this->customerRepository->getById($customer->getId());

        // Process the SAML response from the Foobar SAML controller
        $auth = $subject->_getSAMLAuth();
        $auth->processResponse();
        $attributes = $auth->getAttributes();

        // Determine the appropriate group based on external API call
        $groupId = $this->resolveCustomerGroup($attributes);

        // If no valid group is found, send a denial email and log out
        if ($groupId === null) {
            $this->inlineTranslation->suspend();
            $storeId = $this->storeManager->getStore()->getId();
            $fromTo = $this->helper->getFromStore();
            $currentDateTime = new \DateTime();
            $formattedDateTime = $currentDateTime->format('d-m-Y H:i');

            $templateVars = [
                'first_name' => $customerModel->getFirstname(),
                'last_name'  => $customerModel->getLastname(),
                'email'      => $customerModel->getEmail(),
                'time'       => $formattedDateTime
            ];

            // Attempt to notify and redirect
            $this->sendDeniedEmail($storeId, $templateVars, $fromTo);

            return $this->resultFactory
                ->create(ResultFactory::TYPE_REDIRECT)
                ->setUrl('customer/account/logout');
        }

        // Update the customer's group in both session and the database
        $customerModel->setGroupId($groupId);
        $customerSession->setCustomerGroupId($groupId);
        $this->customerRepository->save($customerModel);

        // Also update the quote to reflect the new group
        $quote = $this->checkoutSession->getQuote();
        $quote->setCustomerGroupId($groupId);
        $quote->setCustomer($customerModel);
        $this->quoteRepository->save($quote);

        // Return the original result, now that the group has been updated
        return $result;
    }

    /**
     * Sends an "access denied" email to the "From" address and 
     * any additional recipients listed in the module configuration.
     *
     * @param  int    $storeId       The store ID context
     * @param  array  $templateVars  Variables to be injected into the email template
     * @param  array  $fromTo        Sender array containing 'email' and 'name'
     * @return void
     */
    private function sendDeniedEmail(int $storeId, array $templateVars, array $fromTo): void
    {
        try {
            // Build and send the email using Magento's TransportBuilder
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('manitou_sso_access_denied_template') // Email template ID
                ->setTemplateOptions([
                    'area'  => Area::AREA_FRONTEND,
                    'store' => $storeId
                ])
                ->setTemplateVars($templateVars)
                ->setFromByScope($fromTo, $storeId)
                ->addTo($fromTo['email']) // 'From' address also receives the denial email
                ->addCc($this->helper->getCopyTo()) // Additional recipients for the denial notice
                ->getTransport();

            $transport->sendMessage();
        } finally {
            // Resume inlined translations and add a Magento notice message
            $this->inlineTranslation->resume();
            $this->messageManager->addNoticeMessage(__('manitou_sso_access_denied'));
        }
    }
}
