<?php
require_once(DIR_SYSTEM . "sendcloud/sendcloud_api.php");
require_once(DIR_SYSTEM . "comercia/util.php");
use comercia\Util;

class ControllerModuleSendcloud extends Controller
{

    private $error = array();

    public function index()
    {
        //load the langauge data
        $data = array();
        Util::load()->language("module/sendcloud", $data);

        $model=Util::load()->model("module/sendcloud");

        //handle the form when finished
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            Util::config()->set('sendcloud', $this->request->post);
            Util::session()->success = $data['msg_settings_saved'];
            Util::response()->redirect(Util::route()->extension());
        }

        //prepare some information for the form
        $statuses = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'order_status');
        $data['statuses'] = $statuses->rows;


        $formFields = array("sendcloud_automate", "sendcloud_api_key", "sendcloud_api_secret", "sendcloud_address2_as_housenumber",  //basic information
            "sendcloud_checkout_route", "sendcloud_checkout_picker_selector", "sendcloud_checkout_picker_position", //where to inject the location picker
            "sendcloud_checkout_selector_address","sendcloud_checkout_selector_address2","sendcloud_checkout_selector_city","sendcloud_checkout_selector_postcode", //some checkout fields
            "sendcloud_checkout_selector_fake_click" //when the selector finishes , the user might want to fake a click
        );

        $picker_positions = array(
            "checkout_picker_selector_position_before" => "before",
            "checkout_picker_selector_position_after" => "after",
            "checkout_picker_selector_position_replace" => "replace",
            "checkout_picker_selector_position_prepend" => "prepend",
            "checkout_picker_selector_position_append" => "append",
        );

        $pickerPresets=$model->getPickerPresets();


        //place the prepared data into the form
        $form = Util::form($data)
            ->fillFromSessionClear("error_warning", "success")
            ->fillFromPost($formFields)
            ->fillFromConfig($formFields)
            ->fillSelectboxOptions("checkout_picker_position_options",$picker_positions)
            ->fillSelectboxOptions("checkout_presets",Util::arrayHelper()->keyToVal($pickerPresets))
        ;
        Util::breadcrumb($data)
            ->add("text_home", "comon/home")
            ->add("settings_title", "module/sendcloud");


        //handle document related things
        Util::document()->setTitle(Util::language()->heading_title);
        Util::document()->addVariable("picker_presets",$pickerPresets);
        util::document()->addScript("view/javascript/sendcloud.js");


        //create links
        $data['form_action'] = Util::url()->link('module/sendcloud');
        $data['cancel'] = Util::url()->link(Util::route()->extension());


        //create a response

        Util::response()->view("module/sendcloud.tpl", $data);
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', Util::route()->extension())) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }


    public function bulk()
    {
        $sendcloud_settings = Util::config()->getGroup('sendcloud');
        Util::load()->language("module/sendcloud");

        if (!empty($sendcloud_settings['sendcloud_api_key']) && !empty($sendcloud_settings['sendcloud_api_secret'])) {
            $api = new SendcloudApi('live', $sendcloud_settings['sendcloud_api_key'], $sendcloud_settings['sendcloud_api_secret']);
        } else {
            $this->showErrorMessage($this->language->get('msg_no_api_settings'));
        }

        if (!Util::request()->post()->selected) {
            $message = $this->language->get('error_no_orders');
            $this->showErrorMessage($message);
        }
        $selected = Util::request()->post()->selected;
        $order_model = Util::load()->model("sale/order");

        $shipping_methods = $api->shipping_methods->get();

        $orders = Array();
        $errors = Array();
        $errors['no_shipping_details'] = Array();
        $errors['no_shipping_method'] = Array();

        foreach ($selected as $key => $s) {
            $order = $order_model->getOrder($s);
            if ($order['shipping_iso_code_2'] == "") {
                $errors['no_shipping_details'][] = $order['order_id'];
            }
        }

        if (!empty($errors['no_shipping_details'])) {

            $order_error_ids = implode(', ', $errors['no_shipping_details']);
            $message = Util::language()->msg_order_no_shipping . " " . $order_error_ids;
            $this->showErrorMessage($message);
            Util::response()->redirect("sale/order");
        }

        foreach ($selected as $key => $s) {
            $order = $order_model->getOrder($s);
            $shipment_id = $this->getSuitableCountry($shipping_methods, $order);

            if ($shipment_id) {
                $order['sendcloud_shipment_id'] = $shipment_id;
                $orders[] = $order;
            } else {
                $errors['no_shipping_method'][] = $order['order_id'];
            }
        }

        if (!empty($errors['no_shipping_method'])) {

            $order_error_ids = implode(', ', $errors['no_shipping_method']);
            $message = Util::language()->msg_process_orders . " " . $order_error_ids;

            $this->showErrorMessage($message);
        }

        foreach ($orders as $order) {

            try {
                $api->parcels->create(array(
                        'name' => $order['shipping_firstname'] . ' ' . $order['shipping_lastname'],
                        'company_name' => $order['shipping_company'],
                        'address' => ($sendcloud_settings['sendcloud_address2_as_housenumber'] ? $order['shipping_address_1'] . ' ' . $order['shipping_address_2'] : $order['shipping_address_1']),
                        'address_2' => ($sendcloud_settings['sendcloud_address2_as_housenumber'] ? '' : $order['shipping_address_2']),
                        'city' => $order['shipping_city'],
                        'postal_code' => $order['shipping_postcode'],
                        'requestShipment' => false,
                        'email' => $order['email'],
                        'telephone' => $order['telephone'],
                        'country' => $order['shipping_iso_code_2'],
                        'shipment' => array(
                            'id' => $order['sendcloud_shipment_id']
                        ),
                        'order_number' => $order['order_id']
                    )
                );
            } catch (SendCloudApiException $exception) {
                // TODO: Validate before transporting instead of catching the API errors.
                $message = $this->language->get('msg_process_orders') . " " . $order_error_ids . $this->language->get('msg_api_error_reason') . $exception->message . '.';
                $this->showErrorMessage($message);
            }

            if ($sendcloud_settings['sendcloud_automate'] !== 0) {
                $this->updateOrderStatus($order);
            }
        }

        Util::session()->success = Util::language()->msg_success;
        Util::response()->redirect("sale/order");
    }

    private function updateOrderStatus($order)
    {
        $order_id = $order['order_id'];
        $sendcloud_settings = util::config()->getGroup('sendcloud');
        $order_status_id = $sendcloud_settings['sendcloud_automate'];
        $notify = false;
        $comment = nl2br($this->language->get('log_message'));
        $date_added = date($this->language->get('date_format_short'));

        // Queries Borrowed from /catalog/model/checkout/order.php
        $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', notify = '" . (int)$notify . "', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
    }

    private function showErrorMessage($message)
    {
        // FIXME: Hack to show error message.
        $this->session->data['success'] = "<span class='alert alert-danger' style='width:100%; width: calc(100% + 22px); float:left; position:relative; top:-29px; left:-11px;'>
		<i class='fa fa-exclamation-circle'></i> " . $message . "</span>";
        $this->response->redirect($this->url->link('sale/order', 'token=' . $this->session->data['token'], 'SSL'));
    }

    private function getSuitableCountry($shipping_methods, $order)
    {

        foreach ($shipping_methods as $sm) {
            if ($sm['id'] == 8) {
                // Workaround: Brievenpost is not suitable for transport scenarios.
                // It does not allow you to change to a correct parcel shipping method in the SendCloud panel.
                continue;
            }
            foreach ($sm['countries'] as $country) {
                if ($country['iso_2'] == $order['shipping_iso_code_2']) {
                    return $sm['id'];
                }
            }
        }
        return false;
    }
}

?>
