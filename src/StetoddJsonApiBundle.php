<?php

declare(strict_types=1);

namespace Stetodd\JsonApiBundle;

use Stetodd\JsonApiBundle\Contract\ResourceTransformerInterface;
use Stetodd\JsonApiBundle\Request\ArgumentResolver\JsonApiValueResolver;
use Stetodd\JsonApiBundle\Request\Query\SortResolver;
use Stetodd\JsonApiBundle\Response\JsonApiResponder;
use Stetodd\JsonApiBundle\Response\TransformerRegistry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class StetoddJsonApiBundle extends AbstractBundle
{
    public const string TRANSFORMER_TAG = 'stetodd_jsonapi.transformer';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @psalm-suppress PossiblyUndefinedMethod, MixedMethodCall */
        $definition->rootNode()
            ->children()
                ->scalarNode('base_url')->isRequired()->cannotBeEmpty()->end()
                ->integerNode('recursion_limit')->defaultValue(4)->end()
                ->arrayNode('relationship_routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('self')->defaultValue('api_{type}_relationship_{relationship}_self')->end()
                        ->scalarNode('related')->defaultValue('api_{type}_relationship_{relationship}_related')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{base_url: string, recursion_limit: int, relationship_routes: array{self: string, related: string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(ResourceTransformerInterface::class)
            ->addTag(self::TRANSFORMER_TAG);

        $services = $container->services();

        $services->set(TransformerRegistry::class)
            ->args([tagged_iterator(self::TRANSFORMER_TAG)]);

        $services->set(SortResolver::class)
            ->args([
                service(TransformerRegistry::class),
                service('request_stack'),
            ])
            ->public();

        $services->set(JsonApiValueResolver::class)
            ->args([
                service('serializer'),
                service('validator')->nullOnInvalid(),
                service('translator')->nullOnInvalid(),
            ])
            ->tag('controller.argument_value_resolver')
            ->tag('kernel.event_subscriber');

        $services->set(JsonApiResponder::class)
            ->args([
                service(TransformerRegistry::class),
                service('request_stack'),
                service('router'),
                $config['base_url'],
                $config['relationship_routes']['self'],
                $config['relationship_routes']['related'],
                $config['recursion_limit'],
                service('serializer')->nullOnInvalid(),
            ]);
    }
}
