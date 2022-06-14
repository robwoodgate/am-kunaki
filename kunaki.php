<?php
/**
 *  Kunaki v2.2
 *  Copyright 2012-2020 (c) R Woodgate
 *  All Rights Reserved
 *
 * ============================================================================
 * Revision History:
 * ----------------
 * 2022-06-14   v2.2    R Woodgate  PHP8 Compatibility update
 * 2021-04-10   v2.1    R Woodgate  Use DI->mail so defaults are populated
 * 2020-01-21   v2.0    R Woodgate  aMember v6 update (fixes email)
 * 2019-10-16   v1.8    R Woodgate  Moved debug messages to new debug log
 * 2018-01-29   v1.7    R Woodgate  Fixed [] operator not supported for strings bug
 * 2015-08-25   v1.6    R Woodgate  Fixed inventory bug
 * 2015-01-21   v1.5    R Woodgate  Added option to always use latest billing plan
 * 2014-07-11   v1.4    R Woodgate  Fixed inventory messaging bug
 * 2013-07-08   v1.3    R Woodgate  Package count bugfix for free products
 * 2013-06-19   v1.2    R Woodgate  Updated validation for aMember v4.2.17+
 * 2013-01-07   v1.1    R Woodgate  Added quantity support and inventory
 * 2012-05-18   v1.0    R Woodgate  Plugin Created
 * ============================================================================
 **/

class Am_Plugin_Kunaki extends Am_Plugin
{
    public const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    public const PLUGIN_REVISION = '2.1';
    public const KUNAKI_URL = 'http://kunaki.com/XMLService.ASP';
    public const KUNAKI_SHIPPED = 'kunaki-shipped';
    public const KUNAKI_PRODUCTS = 'kunaki-products';
    public const KUNAKI_ALWAYS_SHIP = 'kunaki-always-ship';
    public const KUNAKI_ALWAYS_FRESH = 'kunaki-always-fresh';
    public const KUNAKI_INVENTORY = 'kunaki-inventory';

    public function init(): void
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
                ->add(new Am_CustomFieldTextarea(
                    self::KUNAKI_PRODUCTS,
                    "Kunaki Product List",
                    "Enter the Kunaki 10 character product ids in a comma-
            separated list, in the order you want them to be shipped.
            If you want to ship multiple products at once, separate
            with a colon: e.g. PRODUCT1:PRODUCT2,PRODUCT3
            will send products 1 and 2 together and product 3 next
            billing period. You can only ship up the total number
            of rebills in this billing plan."
                ));
        $this->getDi()->billingPlanTable->customFields()
                ->add(new Am_CustomFieldCheckbox(
                    self::KUNAKI_ALWAYS_SHIP,
                    'Kunaki Always Ship',
                    'Select this option if you want the Kunaki product(s) to always be
             shipped regardless of whether member has had it before.'
                ));
        $this->getDi()->billingPlanTable->customFields()
                ->add(new Am_CustomFieldCheckbox(
                    self::KUNAKI_ALWAYS_FRESH,
                    'Kunaki Always Fresh',
                    'Select this option if you want the Kunaki product(s) to always be
            selected from the latest Product List above, rather than whatever
            products were listed at time of original order.'
                ));
        $this->getDi()->userTable->customFields()
                ->add(new Am_CustomFieldTextarea(
                    self::KUNAKI_SHIPPED,
                    "Kunaki Products Shipped",
                    "This is the list of Kunaki products that have
             already been shipped to this member."
                ));
    }

    public function isConfigured()
    {
        return $this->getConfig('userid') && $this->getConfig('password');
    }

    public function onSetupForms(Am_Event_SetupForms $event): void
    {
        $form = new Am_Form_Setup('kunaki');
        $form->setTitle("Kunaki");

        $fs = $form->addFieldset()->setLabel(___('Kunaki Account <img src="https://www.cogmentis.com/lcimg/kunaki.jpg" />'));
        $fs->addText('userid')->setLabel(___('Kunaki User ID'));
        $fs->addText('password', ['size' => 40])->setLabel(___('Kunaki Password'));

        $fs = $form->addFieldset()->setLabel(___('Features'));
        $fs->addAdvCheckbox('debug')->setLabel(___("Debug Messages?\n".'If ticked, debug messages will be written to the aMember Debug Log'));
        $fs->addAdvCheckbox('noship')->setLabel(___('Do Not Ship Products'));
        $fs->addText('maxshipping', ['size' => 5])->setLabel(___('Max Shipping Cost'));
        $fs->addAdvCheckbox('checkinventory')->setLabel(___('Check Inventory?'));

        $gr = $fs->addCheckboxedGroup('warnadmin')->setLabel(___('Email Admin on Order Failure'));
        $gr->addStatic()->setContent(___('Admin Email '));
        $gr->addText('adminemail', ['size' => 40]);
        $form->setDefault('adminemail', $this->getDi()->config->get('admin_email'));

        $form->addFieldsPrefix('misc.kunaki.');
        $this->_afterInitSetupForm($form);
        $event->addForm($form);
    }

    public function onValidateSavedForm(Am_Event_ValidateSavedForm $event): void
    {
        $form = $event->getForm();
        $request = $form->getRawValue();

        // Iterate form to see if any kunaki products being ordered
        // Based on lib/Am/SignupController logic
        $kunaki_products = [];
        foreach ($request as $k => $v) {
            if (0===strpos($k, 'product_id')) {
                foreach ((array)$request[$k] as $product_id) {
                    @list($product_id, $plan_id, $qty) = explode('-', $product_id, 3);
                    $product_id = (int)$product_id;
                    if (!$product_id) {
                        continue;
                    }
                    $p = $event->getDi()->productTable->load($product_id);
                    if ($plan_id > 0) {
                        $p->setBillingPlan(intval($plan_id));
                    }
                    $plan_data = $p->getBillingPlanData();
                    if (strlen(trim($plan_data[self::KUNAKI_PRODUCTS])) > 0) {
                        $kunaki_products[] = $p->title;
                    }
                }
            }
        }

        // If not, exit
        if (0 == count($kunaki_products)) {
            return;
        } // not a kunaki order

        // Log request?
        $this->logDebug("Kunaki: Validating request = ".print_r($request, 1));


        // Validate order country
        if ($request['country'] && !$this->kunakiCheckCountry($request['country'])) {
            $event->addError('<h2><span class="error">Sorry, we cannot ship "'
                                . implode('", "', $kunaki_products) .'" to your country</span></h2>');
        }

        // Validate address given
        if (!$request['street']
                || !$request['city']
                || !$request['zip']
                || !$request['country']
                || (
                    // State only required if US or Canada
                    ('US' == $request['country'] || 'CA' == $request['country'])
                    && !$request['state']
                )
        ) {
            $event->addError('<h2><span class="error">Please specify your full shipping address to order "'
                                . implode('", "', $kunaki_products) . '"</span></h2>');
        }

        //print_rre($request, $kunaki_products);
    }


    // called ONCE per invoice - when first payment received or on free signup
    public function onInvoiceStarted(Am_Event $event): void
    {
        //$this->logDebug('kunaki: called onInvoiceStarted');
        $this->kunakiShipOrder($event);
    }

    // called each payment - including recurring. NB: not free signup
    public function onPaymentAfterInsert(Am_Event_PaymentAfterInsert $event): void
    {
        //$this->logDebug('kunaki: called onPaymentAfterInsert');
        // Only process here if a recurring payment. First payment handled by onInvoiceStarted
        if (1 == $event->getInvoice()->getPaymentsCount()) {
            return;
        }
        $this->kunakiShipOrder($event);
    }

    // Check inventory and warn admin if a Kunaki product hasn't been ordered for over 150 days
    // Products not purchased for a period of 180 days are deleted automatically by Kunaki without warning.
    public function onDaily(): void
    {
        $inventory = $this->getInventory();
        if (empty($inventory) || !$this->getConfig('checkinventory')) {
            return;
        } // Nothing to do

        $alert = [];
        $expired = [];
        foreach ((array)$inventory as $key => $value) {
            // Alert on products ordered more than 150 days ago (86400 = 1 day in secs)
            if ((time() - $value) >= (86400 * 150)) {
                $alert[$key] = (int)((time() - $value) / 86400);
            }

            // Expire products ordered more than 180 days ago (86400 = 1 day in secs)
            if ((time() - $value) >= (86400 * 180)) {
                $expired[$key] = $value;
            }
        }


        // Remove expired products from inventory
        if (count($expired) > 0) {
            $this->setInventory(array_diff($inventory, $expired));
        }


        if (count($alert) > 0) {
            $subject = "Kunaki: Product expiry warning!";
            $msg = "Products not purchased for a period of 180 days will be deleted automatically by Kunaki without warning. "
                 . "The following Kunaki products MAY be at risk of deletion unless they have been ordered elsewhere:\n\n";
            foreach ((array)$alert as $key => $value) {
                $msg .= "$key, last ordered $value days ago\n";
            }
            $this->kunakiWarnAdmin($subject, $msg);
        }
    }

    // Override to selectively log only if Debug mode is set
    public function logDebug($message): void
    {
        if (!$this->getConfig('debug') || !$message) {
            return;
        }
        parent::logDebug($message);
    }

    public function getReadme()
    {
        $version = self::PLUGIN_REVISION;
        return <<<CUT
<strong>Kunaki: aMember Kunaki CD/DVD Product Fulfillment Plugin v$version</strong>

The Kunaki plugin will automatically place an order at Kunaki each time one
of your customers purchases an aMember product containing Kunaki products.

NB: To ensure you don't accidently run up Kunaki costs, this plugin does not work
for access added manually.

<strong>Instructions</strong>

These instructions assume you have created your products at Kunaki already.

 1. Upload this plugin file to:
    <strong>amember/application/default/plugins/misc/</strong> folder.

 2. Enable the Kunaki plugin at

    <strong>aMember Admin -&gt; Setup/Configuration -&gt; Plugins</strong>

 3. Configure the Kunaki plugin at

    <strong>aMember Admin -&gt; Setup/Configuration -&gt; Kunaki</strong>

    a. <strong>Kunaki UserId</strong> -
       Enter the UserId you have registered at Kunaki. Usually your email address.

    b. <strong>Kunaki Password</strong> -
       Your Kunaki account password.

    c. <strong>Max Shipping Cost</strong> -
       The maximum you are prepared to pay for shipping in USD. Orders with
       shipping cost above this limit are rejected. Set '0' or leave blank to
       disable setting. Enter a whole number only - do not enter '$' symbol etc.

    d. <strong>Check Inventory?</strong> -
       Kunaki deletes products which have not been ordered for 180 days. Setting this
       option will alert you by email once per day if any products have not been
       ordered for between 150-180 days. NB: It will only track products which have
       been successfully ordered previously - it cannot track products which have
       been setup but NEVER ordered. NB: It is YOUR responsibility to confirm delete
       dates with Kunaki and take action to save your products from deletion.


    e. <strong>Debug Mode</strong> -
       Setting this option will cause debug messages to be written to the log,
       in addition to regular error messages. Leaving it off will result in only
       error messages being written to the log.

    f. <strong>Notify Admin?</strong> -
       This option will alert you by email if an order fails to go through (usually due to
       an incorrect address, an unsupported country, or because there are no more products
       left to ship). If not set, errors are written to the Error log only.

    g. <strong>Admin Email</strong> -
       This option allows you to specify a custom email for Kunaki order issues. If nothing
       is set, your aMember admin email address will be used. This option only works if the
       'Notify Admin' option is set.

    h. <strong>Do Not Ship</strong> -
       This option will mark orders as 'TEST' so that Kunaki does not process them.

    i. Save Kunaki plugin settings.


 4. Configure the aMember products that will trigger Kunaki orders. You can ship one
    Kunaki product on a lifetime subscription product or you can set up Kunaki products
    to ship at each billing interval on a recurring aMember product.

    a. Visit <strong>aMember Admin -&gt; Manage Products -&gt; Edit</strong>.

    b. <strong>Kunaki Product List</strong> -
       Enter a comma-separated list of your Kunaki product numbers in the order
       you want them to be shipped. Members ordering the product will be shipped one
       Kunaki product from this list each time the product billing occurs/recurs.
       If you want to ship more than one Kunaki product in a billing period, separate
       those products with a colon.

       For example, setting this - PRODUCT1,PRODUCT2:PRODUCT3,PRODUCT4
       will cause product1 to ship first, with product2 and product3 being shipped
       together in the second billing period, and product4 shipping in the third billing
       period.

    c. <strong>Kunaki Always Ship</strong> -
       Normally, the plugin handles Kunaki orders intelligently. If the member already
       has a Kunaki product, it won't re-ship it to them. Ticking the 'Always Ship' box
       on a product will allow the plugin to ship Kunaki products regardless of
       whether the member has had them before - useful for e.g. wholesale products

    d. <strong>Kunaki Always Fresh</strong> -
       Normally, aMember takes a snapshot of the billing plan data at time of inital order, and
       uses this data for all rebills. This means that if you change your Kunaki Product List,
       the change will only affect new orders. Ticking the 'Always Fresh' box on a product
       will force the currently set Kunaki Product List to be used for all customers  - useful
       for DVD of the month type products where the Kunaki Product List is added to over time.

    e. Save product settings.

    f. Repeat steps a-c for each product that ships Kunaki product.

 5. Kunaki products that have been shipped to the member are stored in their user account.
    If you edit the member in aMember Admin , you'll see a field that shows all
    <strong>Kunaki Products Shipped</strong>.

    This field can be edited to add or remove Kunaki products, for example to prevent a
    product being shipped next billing period.


-------------------------------------------------------------------------------

Copyright 2012-2021 (c) Rob Woodgate, Cogmentis Ltd. All Rights Reserved

This file may not be distributed unless permission is given by author.

This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.

For support (to report bugs and request new features) visit: <a href="https://www.cogmentis.com/">www.cogmentis.com/</a>

-------------------------------------------------------------------------------'
CUT;
    }

    public function kunakiShipOrder($event): void
    {
        // Initialise variables
        $noship = $this->getConfig('noship');
        $maxshipping = (int) $this->getConfig('maxshipping');
        $invoice = $event->getInvoice(); /* @var Invoice */
        $user = $event->getUser(); // as it may not be a logged-in user

        // Get array of the Kunaki products already shipped to customer...
        $shipped = trim($user->data()->get(self::KUNAKI_SHIPPED));
        $shipped = explode(',', $shipped);

        // trim white space...
        foreach ($shipped as $key => $value) {
            $shipped[$key] = trim($value);
        }

        // Loop through products being ordered this time ...
        $itemsavailable = false;
        $itemstoship = null;
        foreach ($invoice->getItems() as $invoice_item) {

            // Use original or latest billing plan?
            $p = $this->getDi()->productTable->load(intval($invoice_item->item_id));
            $p->setBillingPlan(intval($invoice_item->billing_plan_id));
            $plan_data = $p->getBillingPlanData();
            if (!empty($plan_data[self::KUNAKI_ALWAYS_FRESH])) {
                $kunaki_products = (isset($plan_data[self::KUNAKI_PRODUCTS])) ? trim($plan_data[self::KUNAKI_PRODUCTS]) : false;
                $kunaki_always_ship = (isset($plan_data[self::KUNAKI_ALWAYS_SHIP])) ? true : false;
            } else {
                $kunaki_products = trim($invoice_item->getBillingPlanData(self::KUNAKI_PRODUCTS));
                $kunaki_always_ship = $invoice_item->getBillingPlanData(self::KUNAKI_ALWAYS_SHIP);
            }


            // Skip product if no Kunaki items found
            if (!$kunaki_products) {
                continue;
            }

            // Ok, we have Kunaki items available in this product
            $itemsavailable = true;

            // Convert to array of product packages and sanitise.
            // Product packages are separated by a comma
            $kunaki_products = explode(',', $kunaki_products);
            foreach ($kunaki_products as $key => $value) {
                $kunaki_products[$key] = trim($value); // trim spaces
            }

            // Work out which Kunaki product package to ship next...
            $payment_count = ($invoice->getPaymentsCount() > 0) ? $invoice->getPaymentsCount() - 1 : 0;
            $this->logDebug("Kunaki: Examining package at array index: $payment_count in product: " . $invoice->getLineDescription());
            $items = array_filter(explode(':', $kunaki_products[$payment_count]));
            foreach ($items as $item) {
                $item = trim($item); // trim spaces

                // Don't re-ship an item unless 'Always Ship' is set
                if (!in_array($item, (array)$shipped) || $kunaki_always_ship) {
                    $itemstoship[] = [$item, $invoice_item->qty];
                    $this->logDebug("Kunaki: Going to ship '$item' (x {$invoice_item->qty}) in product : " . $invoice->getLineDescription());
                }
            }
        }

        // Exit if we didn't find any Kunaki products at all in this invoice
        if (!$itemsavailable) {
            return;
        }

        // Get customer name from invoice
        $customer_name = $invoice->getFirstName() . ' ' . $invoice->getLastName();

        // Get Kunaki Country Name (NB: already validated)
        $country = $invoice->getCountry();
        $countryname = $this->kunakiCheckCountry($country);
        $this->logDebug("Kunaki: Country Name = $countryname, Country = $country");


        // Throw admin warning if we have nothing left to ship
        if (!$itemstoship) {
            $this->logDebug("Kunaki: Nothing available to ship to $customer_name (id:" . $invoice->user_id . ") in: " . $invoice->getLineDescription());
            $subject = 'Kunaki: Nothing available to ship to ' . $customer_name . 'in: ' . $invoice->getLineDescription();
            $msg = "You have just received a recurring payment from $customer_name "
                    . "(id:{$invoice->user_id}) but you have no Kunaki products left "
                    . 'to ship them in: ' . $invoice->getLineDescription()
                    . '. Either they have the Kunaki products already or you have '
                    . 'run out of Kunaki products to ship.';

            $this->kunakiWarnAdmin($subject, $msg);
            return;
        }

        // Ok, finally we are ready to process this order!!!!
        // First, lets get some shipping options from Kunaki.

        $xml_shipping = '<ShippingOptions><Country>' . $countryname . '</Country><State_Province>';
        $xml_shipping .= ("US" == $country || "CA" == $country) ? $invoice->getState() : '';
        $xml_shipping .= '</State_Province><PostalCode>' . $invoice->getZip() . '</PostalCode>';
        foreach ((array) $itemstoship as $item) {
            $xml_shipping .= '<Product><ProductId>' . $item[0] . '</ProductId><Quantity>' . $item[1] . '</Quantity></Product>';
        }
        $xml_shipping .= '</ShippingOptions>';
        $this->logDebug("Kunaki: XML Shipping request: $xml_shipping");


        // Send Shipping options request
        $http = new Am_HttpRequest(self::KUNAKI_URL);
        $http->setMethod(Am_HttpRequest::METHOD_POST);
        $http->setHeader('Content-type', 'text/xml');
        $http->setBody($xml_shipping);
        try {
            $response = $http->send();
        } catch (Exception $e) {
            $this->getDi()->errorLogTable->log('Kunaki: Error sending XML Shipping request: ' . get_class($e) . ":" . $e->getMessage());
            $subject = "Kunaki: Order failed for $customer_name (id:" . $invoice->user_id . ")!";
            $msg = "Could not ship to $customer_name (id:" . $invoice->user_id
                    . ') because of an error sending the XML Shipping request: ' . get_class($e) . ":" . $e->getMessage();
            $this->kunakiWarnAdmin($subject, $msg);
            return; // http error on connection
        }
        if (200 != $response->getStatus()) {
            $this->getDi()->errorLogTable->log('Kunaki: Kunaki XML Shipping Gateway unavailable');
            $subject = "Kunaki: Order failed for $customer_name (id:" . $invoice->user_id . ")!";
            $msg = "Could not ship to $customer_name (id:" . $invoice->user_id
                    . ') because the XML Shipping Gateway was unavailable. Please process manually.';
            $this->kunakiWarnAdmin($subject, $msg);
            return; // http error in response
        }
        $response = $response->getBody();

        // Parse XML response
        $response = str_replace(['<HTML>', '<BODY>'], '', $response);
        $this->logDebug("Kunaki: XML Shipping response: " . var_export($response, 1));

        $shipping = new SimpleXMLElement($response);

        // See if Kunaki returned an error
        if ($shipping->ErrorCode > 0) {
            $this->getDi()->errorLogTable->log('Kunaki: XML Shipping request error: (' . $shipping->ErrorCode . ') ' . $shipping->ErrorText);

            $subject = "Kunaki: Could not get shipping options for $customer_name (id:" . $invoice->user_id . ")!";
            $msg = "Could not ship to $customer_name (id:" . $invoice->user_id . ') because Kunaki returned a shipping options error'
                    . ') whilst trying to order the product:'
                    . $invoice->getLineDescription()
                    . "\n\n(Code: {$shipping->ErrorCode}) " . $shipping->ErrorText;
            $this->kunakiWarnAdmin($subject, $msg);
            return;
        }

        // Find cheapest shipping option!
        $shipping_options = [];
        foreach ($shipping->Option as $option) {
            $shipping_options[(string) $option->Description] = (string) $option->Price;
        }
        $this->logDebug("Kunaki: Shipping Options (unsorted): " . print_r($shipping_options, 1));

        asort($shipping_options, SORT_NUMERIC);
        $cheapest = array_keys(array_slice($shipping_options, 0, 1));
        $cheapest = $cheapest[0];
        $this->logDebug("Kunaki: Max shipping rate: $maxshipping, Cheapest Shipping Option: $cheapest, Cost: " . $shipping_options[$cheapest]);


        // Kill order if shipping is too expensive
        if ($maxshipping > 0 && $shipping_options[$cheapest] > $maxshipping) {
            $this->getDi()->errorLogTable->log("Kunaki: Can't ship to $customer_name (id: {$invoice->user_id}) - Shipping too expensive: $cheapest, Cost:" . $shipping_options[$cheapest]);

            $subject = "Kunaki: Shipping costs over limit for $customer_name (id:{$invoice->user_id})!";
            $msg = "Kunaki can't ship to $customer_name (id: {$invoice->user_id}) "
                    . "because cheapest shipping quote ($cheapest: $" . $shipping_options[$cheapest]
                    . ') was over the Max Shipping Cost ($' . $maxshipping . ') set in the Kunaki plugin config';
            $this->kunakiWarnAdmin($subject, $msg);
            return;
        }

        // Now lets prepare the order
        $xml_order = '<Order><UserId>' . $this->getConfig('userid') . '</UserId><Password>' . $this->getConfig('password') . '</Password><Mode>';
        $xml_order .= ($noship) ? 'TEST' : 'LIVE';
        $xml_order .= '</Mode><Name>' . $customer_name . '</Name><Company></Company><Address1>' . $invoice->getStreet() . '</Address1><Address2></Address2>';
        $xml_order .= '<City>' . $invoice->getCity() . '</City><State_Province>';
        $xml_order .= ("US" == $country || "CA" == $country) ? $invoice->getState() : '';
        $xml_order .= '</State_Province><PostalCode>' . $invoice->getZip() . '</PostalCode><Country>' . $countryname . '</Country><ShippingDescription>' . $cheapest . '</ShippingDescription>';
        foreach ((array) $itemstoship as $item) {
            $xml_order .= '<Product><ProductId>' . $item[0] . '</ProductId><Quantity>' . $item[1] . '</Quantity></Product>';
        }
        $xml_order .= '</Order>';
        $this->logDebug("Kunaki: XML Order request: $xml_order");


        // Finally, send order
        $http = new Am_HttpRequest(self::KUNAKI_URL);
        $http->setMethod(Am_HttpRequest::METHOD_POST);
        $http->setHeader('Content-type', 'text/xml');
        $http->setBody($xml_order);
        try {
            $response = $http->send();
        } catch (Exception $e) {
            $this->getDi()->errorLogTable->log('Kunkai: Error sending XML Order request: ' . get_class($e) . ":" . $e->getMessage());
            $subject = "Kunaki: Order failed for $customer_name (id:" . $invoice->user_id . ")!";
            $msg = "Could not ship to $customer_name (id:" . $invoice->user_id
                    . ') because of an error sending the XML Order request: ' . get_class($e) . ":" . $e->getMessage();
            $this->kunakiWarnAdmin($subject, $msg);
            return; // http error on connection
        }
        if (200 != $response->getStatus()) {
            $this->getDi()->errorLogTable->log('Kunaki: Kunaki XML Order Gateway unavailable');
            $subject = "Kunaki: Order failed for $customer_name (id:" . $invoice->user_id . ")!";
            $msg = "Could not ship to $customer_name (id:" . $invoice->user_id
                    . ') because the XML Order Gateway was unavailable. Please process manually.';
            $this->kunakiWarnAdmin($subject, $msg);
            return; // http error in response
        }
        $response = $response->getBody();

        // Parse XML
        $response = str_replace(['<HTML>', '<BODY>'], '', $response);
        $this->logDebug("Kunaki: XML Order response: " . print_r($response, 1));

        $order = new SimpleXMLElement($response);

        // See if Kunaki returned an error
        if ($order->ErrorCode > 0) {
            $this->getDi()->errorLogTable->log('Kunaki: XML Order request error: ' . $order->ErrorText);
            $subject = "Kunaki: Order failed for $customer_name (id:" . $invoice->user_id . ")!";
            $msg = "Order failed for $customer_name (id:" . $invoice->user_id . ')! '
                    . 'Kunaki returned an ordering error (Code: ' . $order->ErrorCode . ") whilst trying to order a product:\n\n" . $order->ErrorText;
            $this->kunakiWarnAdmin($subject, $msg);
            return;
        }

        // ********* SUCCESS!!!! *********
        // Add the products we just shipped to the inventory
        // And update the member's personal shipped list
        $inventory = (array)$this->getInventory();
        foreach ((array) $itemstoship as $item) {
            $shipped[] = $item[0];
            $inventory[$item[0]] = time();
        }

        $this->setInventory($inventory);
        $user->data()->set(self::KUNAKI_SHIPPED, implode(',', array_filter(array_unique($shipped))));
        $user->data()->update();
    }

    protected function kunakiCheckCountry($country)
    {
        if (!$country) {
            return;
        }

        $row = $this->getDi()->db->selectRow("SELECT title FROM ?_country WHERE country = ?", $country);
        $order_country = $row['title'];

        // Fix mismatch between aMember and Kunaki country names
        // Kunaki still lists Yugoslavia, but this is not supported in aMember
        if ('Hong Kong SAR' == $order_country) {
            $order_country = 'Hong Kong';
        }

        $kunaki_countries = ['Argentina', 'Australia', 'Austria', 'Belgium', 'Brazil', 'Bulgaria', 'Canada', 'China', 'Cyprus', 'Czech Republic', 'Denmark', 'Estonia', 'Finland', 'France', 'Germany', 'Gibraltar', 'Greece', 'Greenland', 'Hong Kong', 'Hungary', 'Iceland', 'Ireland', 'Israel', 'Italy', 'Japan', 'Latvia', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Mexico', 'Netherlands', 'New Zealand', 'Norway', 'Poland', 'Portugal', 'Romania', 'Russia', 'Singapore', 'Slovakia', 'Slovenia', 'Spain', 'Sweden', 'Switzerland', 'Taiwan', 'Turkey', 'Ukraine', 'United Kingdom', 'United States', 'Vatican City', 'Yugoslavia'];

        if (in_array($order_country, $kunaki_countries)) {
            return $order_country;
        } else {
            return;
        }
    }

    protected function kunakiWarnAdmin($subject, $msg): void
    {
        // Email admin if required
        if ($this->getConfig('warnadmin')) {
            $message = "Dear Admin,\n\n";
            $message .= $msg;
            $message .= "\n\nRegards,\n\n" . $this->getDi()->config->get('site_title');

            $adminemail = ($this->getConfig('adminemail')) ?
                    $this->getConfig('adminemail') :
                    $this->getDi()->config->get('admin_email');

            $m = $this->getDi()->mail;
            $m->addTo($adminemail, 'Kunaki Plugin Administrator')
                    ->setSubject($subject)
                    ->setBodyText($message);

            try {
                $m->send();
            } catch (Exception $e) {
                $this->getDi()->errorLogTable->log("Kunkai: Error sending warning email to $adminemail: " . get_class($e) . ":" . $e->getMessage());
                return;
            };
        }
    }

    protected function getState(Invoice $invoice)
    {
        $state = $this->getDi()->stateTable->findFirstBy([
            'state' => $invoice->getState()
                ]);
        return $state ? $state->title : $invoice->getState();
    }

    // Kunaki deletes products which have not been ordered for 180 days, so
    // we track last order date in the inventory and notify admin if a Kunaki
    // product is getting too old.

    protected function getInventory()
    {
        $inventory = $this->getDi()->store->getBlob(self::KUNAKI_INVENTORY);
        $inventory = @unserialize($inventory) ? unserialize($inventory) : [];
        $this->logDebug('Kunaki: Get inventory: '.  print_r($inventory, 1));

        return $inventory;
    }

    protected function setInventory($inventory): void
    {
        $this->logDebug('Kunaki: Setting inventory: '.  print_r($inventory, 1));
        $this->getDi()->store->setBlob(self::KUNAKI_INVENTORY, serialize($inventory));
    }
}
