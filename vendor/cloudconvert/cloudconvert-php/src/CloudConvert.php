<?php

namespace CloudConvert;

use CloudConvert\Handler\WebhookHandler;
use CloudConvert\Hydrator\HydratorInterface;
use CloudConvert\Hydrator\JsonMapperHydrator;
use CloudConvert\Resources\JobsResource;
use CloudConvert\Resources\TasksResource;
use CloudConvert\Resources\UsersResource;
use CloudConvert\Transport\HttpTransport;
use Http\Client\HttpClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CloudConvert
{

    const VERSION = '3.2.1';

    /**
     * @var array
     */
    protected $options;
    /**
     * @var HttpClient
     */
    protected $httpTransport;
    /**
     * @var HydratorInterface
     */
    protected $hydrator;


    /**
     * Api constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);

        $this->options = $resolver->resolve($options);
    }

    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('api_key');
        $resolver->setAllowedTypes('api_key', 'string');

        $resolver->setDefault('sandbox', false);
        $resolver->setAllowedTypes('sandbox', 'boolean');

        $resolver->setDefined('http_client');
        $resolver->setAllowedTypes('http_client', HttpClient::class);

    }


    /**
     * @return HttpTransport
     */
    public function getHttpTransport(): HttpTransport
    {
        if ($this->httpTransport === null) {
            $this->httpTransport = new HttpTransport($this->options);
        }

        return $this->httpTransport;
    }


    /**
     * @return HydratorInterface
     */
    public function getHydrator(): HydratorInterface
    {
        if ($this->hydrator === null) {
            $this->hydrator = new JsonMapperHydrator();
        }
        return $this->hydrator;
    }

    /**
     * @return UsersResource
     */
    public function users(): UsersResource
    {
        return new UsersResource($this->getHttpTransport(), $this->getHydrator());
    }

    /**
     * @return TasksResource
     */
    public function tasks(): TasksResource
    {
        return new TasksResource($this->getHttpTransport(), $this->getHydrator());
    }

    /**
     * @return JobsResource
     */
    public function jobs(): JobsResource
    {
        return new JobsResource($this->getHttpTransport(), $this->getHydrator());
    }

    /**
     * @return WebhookHandler
     */
    public function webhookHandler(): WebhookHandler
    {
        return new WebhookHandler($this->getHydrator());
    }

}
