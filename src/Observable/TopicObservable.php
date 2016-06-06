<?php

namespace Rx\Thruway\Observable;

use Rx\Observable;
use Thruway\Common\Utils;
use Rx\ObserverInterface;
use Thruway\WampErrorException;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Thruway\Message\{
    Message, EventMessage, SubscribedMessage, ErrorMessage, SubscribeMessage, UnsubscribeMessage
};

class TopicObservable extends Observable
{

    private $uri;
    private $options;
    private $messages;
    private $sendMessage;

    function __construct(string $uri, array $options, Observable $messages, callable $sendMessage)
    {
        $this->uri         = $uri;
        $this->options     = (object)$options;
        $this->messages    = $messages;
        $this->sendMessage = $sendMessage;
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $requestId      = Utils::getUniqueId();
        $subscriptionId = null;
        $subscribeMsg   = new SubscribeMessage($requestId, $this->options, $this->uri);

        $subscribedMsg = $this->messages->filter(function (Message $msg) use ($requestId) {
            return $msg instanceof SubscribedMessage && $msg->getRequestId() === $requestId;
        })->take(1);

        $errorMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ErrorMessage && $msg->getErrorRequestId() === $requestId;
            })
            ->flatMap(function (ErrorMessage $msg) {
                return Observable::error(new WampErrorException($msg->getErrorURI(), $msg->getArguments()));
            })
            ->take(1);

        $sub = call_user_func($this->sendMessage, $subscribeMsg)
            ->merge($subscribedMsg)
            ->flatMap(function (SubscribedMessage $subscribedMsg) use (&$subscriptionId) {

                $subscriptionId = $subscribedMsg->getSubscriptionId();

                return $this->messages
                    ->filter(function (Message $msg) use ($subscriptionId) {
                        return $msg instanceof EventMessage && $msg->getSubscriptionId() === $subscriptionId;
                    })
                    ->map(function (EventMessage $msg) {
                        return [$msg->getArguments(), $msg->getArgumentsKw(), $msg->getDetails()];
                    });
            })
            ->merge($errorMsg)
            ->subscribe($observer, $scheduler);

        $disposable = new CompositeDisposable();

        $disposable->add($sub);

        $disposable->add(new CallbackDisposable(function () use (&$subscriptionId) {
            if (!$subscriptionId) {
                return;
            }
            $unsubscribeMsg = new UnsubscribeMessage(Utils::getUniqueId(), $subscriptionId);
            call_user_func($this->sendMessage, $unsubscribeMsg)->take(1)->subscribeCallback();
        }));

        return $disposable;
    }
}