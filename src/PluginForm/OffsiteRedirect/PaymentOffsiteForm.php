<?php

namespace Drupal\commerce_maib\PluginForm\OffsiteRedirect;

use Drupal\commerce_maib\Exception\MAIBException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_maib\MAIBGateway;

/**
 * Class PaymentOffsiteForm.
 *
 * @package Drupal\commerce_maib\PluginForm\OffsiteRedirect
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $redirect_url = $payment_gateway_plugin->getRedirectUrl();
    $redirect_method = PaymentOffsiteForm::REDIRECT_POST;

    $capture = !empty($form['#capture']);
    $currency = $payment->getAmount()->getCurrencyCode();
    $currencyObj = \Drupal::entityTypeManager()->getStorage('commerce_currency')->load($currency);
    $amount = $payment->getAmount()->getNumber();

    $client_ip_addr = $payment->getOrder()->getIpAddress();
    $description = (string) $this->t('Order #@id', ['@id' => $payment->getOrderId()]);
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $transaction_id = NULL;
    try {
      $client = $payment_gateway_plugin->getClient();

      if ($capture) {
        $response = $client->registerSmsTransaction($amount, $currencyObj->getNumericCode(), $client_ip_addr, $description, $language);
      }
      else {
        $response = $client->registerDmsAuthorization($amount, $currencyObj->getNumericCode(), $client_ip_addr, $description, $language);
      }

      if (isset($response['error'])) {
        throw new MAIBException($this->t('MAIB error: @error', ['@error' => $response['error']]));
      }
      elseif (!isset($response[MAIBGateway::MAIB_TRANSACTION_ID])) {
        throw new MAIBException($this->t('MAIB error: Missing TRANSACTION_ID'));
      }
      else {
        $transaction_id = $response[MAIBGateway::MAIB_TRANSACTION_ID];
      }

      $pending_payment = $payment_gateway_plugin->storePendingPayment($payment->getOrder(), $transaction_id);
    }
    catch (\Exception $e) {
      \Drupal::logger('commerce_maib')->error($e->getMessage());
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $e->getMessage()]));
    }

    \Drupal::logger('commerce_maib')->notice('Got transaction id @trans_id for order @order and payment @payment',
      [
        '@trans_id' => $transaction_id,
        '@order' => $payment->getOrderId(),
        '@payment' => $pending_payment->id(),
      ]);

    $data = [
      MAIBGateway::MAIB_TRANS_ID => $transaction_id,
    ];

    $form = $this->buildRedirectForm($form, $form_state, $redirect_url, $data, $redirect_method);

    return $form;
  }

}
