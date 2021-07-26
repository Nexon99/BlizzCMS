<?php
/**
 * BlizzCMS
 *
 * @author  WoW-CMS
 * @copyright  Copyright (c) 2017 - 2021, WoW-CMS.
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://wow-cms.com
 */
defined('BASEPATH') OR exit('No direct script access allowed');

require_once(APPPATH . 'modules/donate/vendor/autoload.php');

use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;

use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;

use PayPalHttp\HttpException;

class Donate extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();

        mod_located('donate', true);

        require_login();

        $this->load->model([
            'donation_logs_model' => 'donation_logs',
        ]);

        $this->load->language('donate', $this->language->current());

        $this->template->set_partial('alerts', 'static/alerts');
    }

    public function index()
    {
        $this->template->title(config_item('app_name'), lang('donate'));

        $this->template->build('index');
    }

    public function paypal_donate()
    {
        if (config_item('paypal_gateway') === 'false')
        {
            show_404();
        }

        $minimal = config_item('paypal_minimal_amount');

        $this->form_validation->set_rules('amount', lang('amount'), 'trim|required|is_natural|greater_than_equal_to['.$minimal.']');

        if ($this->form_validation->run() == false)
        {
            return $this->index();
        }
        else
        {
            $amount    = $this->input->post('amount', true);
            $user      = $this->session->userdata('id');
            $currency  = config_item('paypal_currency');
            $reference = $user . bin2hex(random_bytes(16));
            $ip        = $this->input->ip_address();

            $request = new OrdersCreateRequest();
            $request->prefer('return=representation');
            $request->body = [
                'intent'         => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $reference,
                        'description'  => lang('virtual_currency'),
                        'amount' => [
                            'value'         => $amount,
                            'currency_code' => $currency
                        ]
                    ]
                ],
                'application_context' => [
                    'cancel_url' => site_url('donate/paypal/cancel'),
                    'return_url' => site_url('donate/paypal/check')
                ]
            ];

            try {
                $client   = $this->_paypal_client();
                $response = $client->execute($request);
            } catch (HttpException $exception) {
                $error = json_decode($exception->getMessage());

                show_error($error->message, 500, 'Error: ' . $error->details[0]->issue);
            }

            $points = ($amount / (int) config_item('paypal_currency_rate')) * (int) config_item('paypal_points_rate');

            $this->donation_logs->create([
                'user_id'         => $user,
                'order_id'        => $response->result->id,
                'reference_id'    => $reference,
                'payment_gateway' => 'PayPal',
                'points'          => $points,
                'amount'          => $amount,
                'currency'        => $currency,
                'ip'              => $ip,
                'created_at'      => current_date()
            ]);

            redirect($response->result->links[1]->href);
        }
    }

    public function paypal_check()
    {
        $token = $this->input->get('token', true);

        if (empty($token))
        {
            show_404();
        }

        $request = new OrdersCaptureRequest($token);
        $request->prefer('return=representation');

        try {
            $client   = $this->_paypal_client();
            $response = $client->execute($request);
        } catch (HttpException $exception) {
            $error = json_decode($exception->getMessage());

            show_error($error->message, 500, 'Error: ' . $error->details[0]->issue);
        }

        $log = $this->donation_logs->find(['order_id' => $token, 'payment_status' => 'PENDING']);

        if (empty($log))
        {
            $this->session->set_flashdata('error', lang('order_notfound'));
            redirect(site_url('donate'));
        }

        if ($response->result->purchase_units[0]->payments->captures[0]->status !== 'COMPLETED')
        {
            $this->session->set_flashdata('error', lang('order_process_error'));
            redirect(site_url('donate'));
        }

        if ($log->rewarded !== 'NO')
        {
            $this->session->set_flashdata('error', lang('order_already_rewarded'));
            redirect(site_url('donate'));
        }

        $this->users->set(['dp' => 'dp+' . $log->points], ['id' => $log->user_id], false);

        $this->donation_logs->update([
            'payment_id'     => $response->result->purchase_units[0]->payments->captures[0]->id,
            'payment_status' => 'COMPLETED',
            'rewarded'       => 'YES',
            'updated_at'     => current_date()
        ], ['order_id' => $token]);

        $this->session->set_flashdata('success', lang_vars('order_completed', [$token]));
        redirect(site_url('donate'));
    }

    public function paypal_cancel()
    {
        $token = $this->input->get('token', true);

        if (empty($token))
        {
            show_404();
        }

        $log = $this->donation_logs->find(['order_id' => $token, 'payment_status' => 'PENDING']);

        if (empty($log))
        {
            $this->session->set_flashdata('error', lang('order_notfound'));
            redirect(site_url('donate'));
        }

        $this->donation_logs->update([
            'payment_status' => 'DECLINED'
        ], ['order_id' => $token]);

        $this->session->set_flashdata('warning', lang_vars('order_canceled', [$token]));
        redirect(site_url('donate'));
    }

    private function _paypal_client()
    {
        $client = config_item('paypal_client');
        $secret = decrypt(config_item('paypal_secret'));

        $enviroment = (config_item('paypal_mode') === 'production') ? new ProductionEnvironment($client, $secret) : new SandboxEnvironment($client, $secret);

        return new PayPalHttpClient($enviroment);
    }
}
