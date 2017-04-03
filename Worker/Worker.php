<?php

namespace SqsPhpBundle\Worker;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use SqsPhpBundle\Queue\Queue;
use Aws\Sqs\SqsClient;

class Worker implements ContainerAwareInterface {

    use ContainerAwareTrait;

    const SERVICE_NAME = 0;
    const SERVICE_METHOD = 1;

    private $sqs_client;
    private $queue;
    protected $max_retries = 2;
    protected $retries = 0;

    public function __construct(SqsClient $an_sqs_client) {
        $this->sqs_client = $an_sqs_client;
    }

    //Short poll is the default behavior where a weighted random set of machines
    // is sampled on a ReceiveMessage call. This means only the messages on the 
    // sampled machines are returned. If the number of messages in the queue 
    // is small (less than 1000), it is likely you will get fewer messages 
    // than you requested per ReceiveMessage call. If the number of messages 
    // in the queue is extremely small, you might not receive any messages in
    //  a particular ReceiveMessage response; in which case you should repeat the request.
    //http://docs.aws.amazon.com/aws-sdk-php/v2/api/class-Aws.Sqs.SqsClient.html#_receiveMessage
    public function start(Queue $a_queue) {
        $this->queue = $a_queue;

        while ($this->retries <= $this->max_retries) {
            $this->fetchMessage();
        }
        return;
    }

    private function fetchMessage() {
        $result = $this->sqs_client->receiveMessage(array(
            'QueueUrl' => $this->queue->url(),
        ));

        if (!$result->hasKey('Messages')) {
            sleep(1);      
            ++$this->retries;
            return;
        }

        $all_messages = $result->get('Messages');
        foreach ($all_messages as $message) {
            try {
                $this->runWorker($message);
            } catch (\Exception $e) {
                echo $e;
                continue;
            }

            // Delete message from queue if no error appeared
            $this->deleteMessage($message['ReceiptHandle']);
        }
    }

    private function runWorker($message) {
        $callable = ($this->isService()) ? $this->getServiceCallable() : $this->queue->worker();

        call_user_func(
                $callable, $this->unserializeMessage($message['Body'])
        );
    }

    private function isService() {
        return $this->container->has(
                        $this->queue->worker()[self::SERVICE_NAME]
        );
    }

    private function getServiceCallable() {
        $service = $this->container->get(
                $this->queue->worker()[self::SERVICE_NAME]
        );

        return array(
            $service,
            $this->queue->worker()[self::SERVICE_METHOD]
        );
    }

    protected function unserializeMessage($a_message) {
        return json_decode($a_message, true);
    }

    private function deleteMessage($a_message_receipt_handle) {
        $this->sqs_client->deleteMessage(array(
            'QueueUrl' => $this->queue->url(),
            'ReceiptHandle' => $a_message_receipt_handle
        ));
    }

}
