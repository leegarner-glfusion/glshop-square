<?php
/**
 * Square Webhook class for the Shop plugin.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2020 Lee Garner <lee@leegarner.com>
 * @package     shop
 * @version     v1.3.0
 * @since       v1.3.0
 * @license     http://opensource.org/licenses/gpl-2.0.php
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\square;
use Shop\Payment;
use Shop\Order;
use Shop\Gateway;
use Shop\Currency;
use Shop\Log;
use Shop\Config;


/**
 * Square webhook class.
 * @package shop
 */
class Webhook extends \Shop\Webhook
{
    /**
     * Set up the webhook data from the supplied JSON blob.
     *
     * @param   string  $blob   JSON data
     */
    public function __construct($blob='')
    {
        $this->setSource('square');
        // Load the payload into the blob property for later use in Verify().
        if (isset($_POST['headers'])) {
            $this->blob = base64_decode($_POST['vars']);
            $this->setHeaders(json_decode(base64_decode($_POST['headers']),true));
        } else {
            $this->blob = file_get_contents('php://input');
            $this->setHeaders(NULL);
        }
        $this->setTimestamp();
        $this->setData(json_decode($this->blob));
    }


    /**
     * Perform the necessary actions based on the webhook.
     *
     * @return  boolean     True on success, False on error
     */
    public function Dispatch() : bool
    {
        global $LANG_SHOP;

        // Be optimistic. Also causes a synthetic 200 return for unhandled events.
        $retval = true;
        $object = $this->getData()->data->object;
        if (!$object) {
            return false;
        }

        // If not unique, return true since it's doesn't need to be resent
        if (!$this->isUniqueTxnId()) {
            // Duplicate transaction, not an error.
            return true;
        }

        switch ($this->getEvent()) {
        case 'invoice.payment_made':
            $invoice = $object->invoice;
            if ($invoice) {
                $ord_id = $invoice->invoice_number;
                $status = $invoice->status;
                if ($ord_id) {
                    $this->setOrderID($ord_id);
                    $Order = Order::getInstance($ord_id);
                    if (!$Order->isNew()) {
                        $pmt_req = $invoice->payment_requests;
                        if ($pmt_req && isset($pmt_req[0])) {
                            $this->setRefID($pmt_req[0]->uid);
                            $this->logIPN();
                            $bal_due = $Order->getBalanceDue();
                            $completed = $pmt_req[0]->total_completed_amount_money;
                            $computed = $pmt_req[0]->computed_amount_money;
                            $sq_paid = Currency::getInstance($completed->currency)
                                ->fromInt($completed->amount);
                            if ($status == 'PAID' && $sq_paid - $bal_due >= 0) {
                                // Invoice is paid in full by this payment
                                $amt_paid = $bal_due;
                            } elseif ($status == 'PARTIALLY_PAID') {
                                // Have to figure out the amount of this payment by deducting
                                // the "next payment amount"
                                $amt_paid = $sq_paid;
                            } else {
                                $amt_paid = 0;
                            }
                            if ($amt_paid > 0) {
                                $Pmt = Payment::getByReference($this->getRefID());
                                if ($Pmt->getPmtID() == 0) {
                                    $Pmt->setRefID($this->getRefID())
                                        ->setAmount($amt_paid)
                                        ->setGateway($this->getSource())
                                        ->setMethod($this->GW->getDscp())
                                        ->setComment('Webhook ' . $this->getData()->event_id)
                                        ->setOrderID($this->getOrderID());
                                    $retval = $Pmt->Save();
                                }
                            }
                        }
                    }
                }
            }
            break;
        case 'payment':
        case 'payment.created':
            $payment = $object->payment;
            if ($payment) {
                $this->setRefID($payment->id);
                $this->setTxnDate($payment->created_at);
                $this->logIPN();
                $amount_money = $payment->amount_money->amount;
                if (
                    $amount_money > 0 &&
                    ( $payment->status == 'APPROVED' || $payment->status == 'COMPLETED')
                ) {
                    $currency = $payment->amount_money->currency;
                    $this_pmt = Currency::getInstance($currency)->fromInt($amount_money);
                    $order_id = $payment->order_id;
                    $sqOrder = $this->GW->getOrder($order_id);
                    $this->setOrderID($sqOrder->getResult()->getOrder()->getReferenceId());
                    $Order = Order::getInstance($this->getOrderID());
                    $is_complete = $payment->status == $this->GW->getConfig('pmt_complete_status');
                    if (!$Order->isNew()) {
                        $Pmt = Payment::getByReference($this->refID);
                        if ($Pmt->getPmtID() == 0) {
                            $Pmt->setRefID($this->refID)
                                ->setAmount($this_pmt)
                                ->setGateway($this->getSource())
                                ->setMethod($this->GW->getDscp())
                                ->setComment('Webhook ' . $this->getID())
                                ->setComplete($is_complete ? 1 : 0)
                                ->setStatus($payment->status)
                                ->setOrderID($this->getOrderID())
                                ->Save();
                        }
                        if ($Pmt->isComplete()) {
                            // Process if fully paid. May be "Approved" so
                            // wait for a payment.update before processing.
                            $retval = $this->handlePurchase();
                            $this->setVerified(true);
                        }
                    }
                }
            }
            break;

        case 'payment.updated':
            $payment = $object->payment;
            if ($payment->id) {
                $this->setRefID($payment->id);
                if (isset($payment->updated_at)) {
                    $this->setTxnDate($payment->updated_at);
                } elseif (isset($payment->created_at)) {
                    $this->setTxnDate($payment->created_at);
                } else {
                    $this->setTxnDate(NULL);    // fall back to current timestamp
                }
                if (
                    $payment->status == 'COMPLETED' ||
                    $payment->status == 'CAPTURED'
                ) {
                    $Pmt = Payment::getByReference($this->refID);
                    if (!$Pmt->isComplete()) {
                        // Process if not already complete
                        $Cur = Currency::getInstance($payment->total_money->currency);
                        if ($Pmt->getPmtID() == 0) {
                            Log::debug("Payment not found: " . var_export($data,true));
                        } elseif (
                            $payment->total_money->amount == $Cur->toInt($Pmt->getAmount())
                        ) {
                            $Pmt->setComplete(1)->setStatus($payment->status)->Save();
                            $this->Order = Order::getInstance($Pmt->getOrderID());
                            $this->handlePurchase();    // process if fully paid
                            $this->setVerified(true);
                            $this->setOrderID($Pmt->getOrderID());
                        }
                    }
                    $retval = true;
                }
                $this->logIPN();
            }
            break;

        case 'invoice.created':
        case 'invoice.updated':
        case 'invoice.published':
            $invoice = $object->invoice;
            if ($invoice) {
                $inv_num = $invoice->invoice_number;
                if (!empty($inv_num)) {
                    $Order = Order::getInstance($inv_num);
                    if (!$Order->isNew()) {
                        $this->setOrderID($inv_num);
                        $this->logID = $this->logIPN();
                        $Pmt = Payment::getByReference($this->getID());
                        //if ($Pmt->getPmtID() == 0) {
                            $Pmt->setRefID($this->getID())
                                ->setGateway($this->getSource())
                                ->setMethod($this->GW->getDscp() . ' ' . $LANG_SHOP['invoice'])
                                ->setComment($invoice->id)
                                ->setOrderID($Order->getOrderId())
                                ->setComplete(0)
                                ->setStatus($invoice->status)
                                ->Save();
                        //}
                        $this->setOrderID($inv_num);
                        Log::debug("Invoice created for {$this->getOrderID()}");
                        // Always OK to process for a Net-30 invoice
                        $this->handlePurchase();
                        //$terms_gw = \Shop\Gateway::create($Order->getPmtMethod());
                        //$Order->updateStatus($terms_gw->getConfig('after_inv_status'));
                        $retval = true;
                    } else {
                        Log::debug("Order number '$inv_num' not found for Square invoice");
                    }
                }
            }
            break;

        case 'refund.created':
            $this->setRefId($Refund->payment_id);
            $this->logID = $this->logIPN();
            break;

        case 'refund.updated':
            $Refund = $object->refund;
            $refund_amt = $Refund->amount_money->amount / 100;
            $this->setPayment($refund_amt * -1);
            $this->setRefId($Refund->payment_id);
            $this->setPmtMethod('refund');
            $origPayment = Payment::getByReference($Refund->payment_id);
            if ($Refund->status == 'COMPLETED' && $origPayment->getPmtId() > 0) {
                $this->setComplete();
                $Order = Order::getInstance($origPayment->getOrderId());
                if (!$Order->isNew()) {
                    // Found a valid order
                    $this->setOrderId($Order->getOrderId());
                    $item_total = 0;
                    foreach ($Order->getItems() as $key=>$Item) {
                        $item_total += $Item->getQuantity() * $Item->getPrice();
                    }
                    $item_total += $Order->miscCharges();

                    if ($item_total <= $refund_amt) {
                        $this->handleFullRefund($Order);
                        /*
                        // Completely refunded, let the items handle any refund actions.
                        // None for catalog items since there's no inventory,
                        // but plugin items may need to do something.
                        foreach ($Order->getItems() as $key=>$OI) {
                            $OI->getProduct()->handleRefund($OI, $this->IPN);
                            // Don't care about the status, really.  May not even be
                            // a plugin function to handle refunds
                        }
                        // Update the order status to Refunded
                        $Order->updateStatus('refunded');
                         */
                    }
                    $this->recordPayment();
                    $msg = sprintf($LANG_SHOP['refunded_x'], $this->getCurrency()->Format($refund_amt));
                    $Order->Log($msg);
                }
            }
            break;
        }

        return $retval;
    }


    /**
     * Verify that the webhook is valid.
     *
     * @return  boolean     True if valid, False if not.
     */
    public function Verify() : bool
    {
        global $_CONF;

        // Check that the blob was decoded successfully.
        // If so, extract the key fields and set Webhook variables.
        $data = $this->getData();
        if (!is_object($data) || !isset($data->event_id)) {
            return false;
        }
        $this->setID($data->event_id);
        $this->setEvent($data->type);
        $this->GW = Gateway::getInstance($this->getSource());
        if (!$this->GW) {
            return false;
        }
        if (Config::get('sys_test_ipn') && isset($_GET['testhook'])) {
            $this->setStatusMsg('Testing Webhook');
            return true;      // used during testing to bypass verification
        }

        $gw = \Shop\Gateway::create($this->getSource());
        $notificationSignature = $this->getHeader('X-Square-Signature');
        $notificationUrl = $_CONF['site_url'] . '/shop/hooks/webhook.php?_gw=square';
        $stringToSign = $notificationUrl . $this->blob;
        $webhookSignatureKey = $gw->getConfig('webhook_sig_key');

        // Generate the HMAC-SHA1 signature of the string
        // signed with your webhook signature key
        $hash = hash_hmac('sha1', $stringToSign, $webhookSignatureKey, true);
        $generatedSignature = base64_encode($hash);
        Log::debug("Generated Signature: " . $generatedSignature);
        Log::debug("Received Signature: " . $notificationSignature);
        // Compare HMAC-SHA1 signatures.
        return hash_equals($generatedSignature, $notificationSignature);
    }

}
