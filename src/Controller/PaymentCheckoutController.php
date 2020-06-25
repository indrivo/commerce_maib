<?php

namespace Drupal\commerce_maib\Controller;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\commerce_maib\MAIBGateway;
use Drupal\commerce_maib\Exception\MAIBException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_cart\CartSession;
use Drupal\commerce_cart\CartSessionInterface;

/**
 * Provides checkout endpoints for off-site payments.
 *
 * @package Drupal\commerce_maib\Controller
 */
class PaymentCheckoutController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The checkout order manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   */
  protected $checkoutOrderManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type manager.
   *
   * @var Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The cart session.
   *
   * @var \Drupal\commerce_cart\CartSessionInterface
   */
  protected $cartSession;

  /**
   * Maib config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $maibConfig;

  /**
   * Constructs a new PaymentCheckoutController object.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManagerInterface $checkout_order_manager
   *   The checkout order manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager object.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Request stack object.
   * @param \Drupal\commerce_cart\CartSessionInterface $cart_session
   *   Commerce session object.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory object.
   */
  public function __construct(CheckoutOrderManagerInterface $checkout_order_manager, MessengerInterface $messenger, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager, RequestStack $requestStack, CartSessionInterface $cart_session, ConfigFactoryInterface $configFactory) {
    $this->checkoutOrderManager = $checkout_order_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $requestStack;
    $this->cartSession = $cart_session;
    $this->maibConfig = $configFactory->get('commerce_payment.commerce_payment_gateway.maib');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('messenger'),
      $container->get('logger.channel.commerce_payment'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('commerce_cart.cart_session'),
      $container->get('config.factory')
    );
  }

  /**
   * Provides the "return" checkout payment page.
   *
   * Redirects to the next checkout page, completing checkout.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   */
  public function returnPage(Request $request, RouteMatchInterface $route_match) {
    $transaction_id = $this->getTransactionId($request);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->getPayment($transaction_id);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    $this->redirectToCheckoutFinishedUrl($order, 'return', $transaction_id);

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->get('payment_gateway')->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
      throw new AccessException('The payment gateway for the order does not implement ' . OffsitePaymentGatewayInterface::class);
    }

    try {
      $payment_gateway_plugin->onReturn($order, $request);
    }
    catch (PaymentGatewayException $e) {
      $this->logger->error($e->getMessage());
      $this->messenger->addError(t('Payment failed at the payment server. Please review your information and try again.'));
    }

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $this->checkoutOrderManager->getCheckoutFlow($order);
    $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $redirect_step_id = $checkout_flow_plugin->getNextStepId($step_id);

    $checkout_flow_plugin->redirectToStep($redirect_step_id);
  }

  /**
   * Provides the "cancel" checkout payment page.
   *
   * Redirects to the previous checkout page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   */
  public function cancelPage(Request $request, RouteMatchInterface $route_match) {
    $transaction_id = $this->getTransactionId($request);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->getPayment($transaction_id);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    $this->redirectToCheckoutFinishedUrl($order, 'cancel', $transaction_id);

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->get('payment_gateway')->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
      throw new AccessException('The payment gateway for the order does not implement ' . OffsitePaymentGatewayInterface::class);
    }
    \Drupal::logger('commerce_maib')
      ->notice('Voided payment @payment with transaction id @trans_id for order @order',
        [
          '@trans_id' => $transaction_id,
          '@order' => $order->id(),
          '@payment' => $payment->id(),
        ]);
    $payment->delete();
    $payment_gateway_plugin->onCancel($order, $request);

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
    $previous_step_id = $checkout_flow_plugin->getPreviousStepId($step_id);
    $checkout_flow_plugin->redirectToStep($previous_step_id);
  }

  /**
   * Get transaction ID from requrest.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string
   *   Transaction ID.
   */
  public function getTransactionId(Request $request) {
    $transaction_id = $request->request->get(MAIBGateway::MAIB_TRANS_ID);
    if (empty($transaction_id)) {
      throw new MAIBException($this->t('MAIB return redirect error: Missing TRANSACTION_ID'));
    }
    return $transaction_id;
  }

  /**
   * Get commerce payment based on MAIB transaction ID.
   *
   * @param string $transaction_id
   *   Transaction ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  public function getPayment($transaction_id) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties([
      'remote_id' => $transaction_id,
      'payment_gateway' => commerce_maib_get_all_gateway_ids(),
    ]);
    if (empty($payments)) {
      throw new MAIBException($this->t('MAIB error: failed to locate payment for TRANSACTION_ID @id', ['@id' => $transaction_id]));
    }
    return reset($payments);
  }

  /**
   * Checks access for the form page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function checkAccess(RouteMatchInterface $route_match, AccountInterface $account) {
    $transaction_id = $this->requestStack->getCurrentRequest()->get(MAIBGateway::MAIB_TRANS_ID);
    if (empty($transaction_id)) {
      \Drupal::logger('commerce_maib')->notice('Return URL access without providing transaction ID. Data: @data.',
        ['@data' => Json::encode($this->requestStack->getCurrentRequest()->request->all())]);
      return AccessResult::forbidden();
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->getPayment($transaction_id)->getOrder();

    if ($order->getState()->getId() == 'canceled') {
      \Drupal::logger('commerce_maib')
        ->notice('Return URL access with transaction ID @trans_id for an cancelled order @order.',
          ['@trans_id' => $transaction_id, '@order' => $order->id()]);
      return AccessResult::forbidden()->addCacheableDependency($order);
    }

    // The user can checkout only their own non-empty orders.
    if ($account->isAuthenticated()) {
      $customer_check = $account->id() == $order->getCustomerId();
    }
    else {
      $active_cart = $this->cartSession->hasCartId($order->id(), CartSession::ACTIVE);
      $completed_cart = $this->cartSession->hasCartId($order->id(), CartSession::COMPLETED);
      $customer_check = $active_cart || $completed_cart;
    }

    $access = AccessResult::allowedIf($customer_check)
      ->andIf(AccessResult::allowedIf($order->hasItems()))
      ->andIf(AccessResult::allowedIfHasPermission($account, 'access checkout'))
      ->addCacheableDependency($order);

    return $access;
  }

  /**
   * Checkout flow plugin does not work when commerce order is not available as route param.
   *
   * A redirect to commerce return/cancel url will be performed.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $type
   *   Request type, cancel or return.
   * @param string $remote_id
   *   Remote id.
   *
   * @see https://www.drupal.org/project/commerce/issues/2931044
   *
   * @throws NeedsRedirectException
   */
  public function redirectToCheckoutFinishedUrl(OrderInterface $order, $type, $remote_id) {
    throw new NeedsRedirectException(Url::fromRoute('commerce_payment.checkout.' . $type, [
      'commerce_order' => $order->id(),
      'step' => $order->get('checkout_step')->value,
    ], [
      'query' => [MAIBGateway::MAIB_TRANS_ID => $remote_id],
    ])->toString());
  }

}
