<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\TwigSupportBundle\Test\Filesystem;

use Contao\CoreBundle\Config\ResourceFinder;
use Contao\CoreBundle\Config\ResourceFinderInterface;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\TestCase\ContaoTestCase;
use HeimrichHannot\TwigSupportBundle\Filesystem\TwigTemplateLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class TwigTemplateLocatorTest extends ContaoTestCase
{
    public function createTestInstance(array $parameter = [])
    {
        if (!isset($parameter['kernel'])) {
            $parameter['kernel'] = $this->createMock(KernelInterface::class);
        }

        if (!isset($parameter['resource_finder'])) {
            $parameter['resource_finder'] = $this->createMock(ResourceFinderInterface::class);
        }

        if (!isset($parameter['scope_matcher'])) {
            $parameter['scope_matcher'] = $this->createMock(ScopeMatcher::class);
        }

        if (!isset($parameter['request_stack'])) {
            $parameter['request_stack'] = $this->createMock(RequestStack::class);
            $parameter['request_stack']->method('getCurrentRequest')->willReturn($this->createMock(Request::class));
        }

        return new TwigTemplateLocator(
            $parameter['kernel'],
            $parameter['resource_finder'],
            $parameter['request_stack'],
            $parameter['scope_matcher'],
            $this->createMock(Stopwatch::class)
        );
    }

    public function testGenerateContaoTwigTemplatePathsEmpty()
    {
        $kernel = $this->createMock(Kernel::class);
        $kernel->method('getBundles')->willReturn([]);
        $kernel->method('getProjectDir')->willReturn(__DIR__.'/../Fixtures/templateLocator/empty');

        $resourceFinder = $this->getMockBuilder(ResourceFinderInterface::class)->setMethods(['find', 'findIn', 'name', 'getIterator'])->getMock();
        $resourceFinder->method('findIn')->willReturnSelf();
        $resourceFinder->method('name')->willReturnSelf();
        $resourceFinder->method('getIterator')->willReturn([]);

        $instance = $this->createTestInstance([
            'kernel' => $kernel,
            'resource_finder' => $resourceFinder,
        ]);
        $this->assertEmpty($instance->getTemplates(false, true));
    }

    public function testGenerateContaoTwigTemplatePathsBundles()
    {
        [$kernel, $resourceFinder] = $this->buildKernelAndResourceFinderForBundlesAndPath(['dolarBundle', 'ipsumBundle'], 'bundles');

        $instance = $this->createTestInstance([
            'kernel' => $kernel,
            'resource_finder' => $resourceFinder,
        ]);
        $this->assertNotEmpty($instance->getTemplates(false, true));
    }

    public function testGetTemplatePath()
    {
        [$kernel, $resourceFinder] = $this->buildKernelAndResourceFinderForBundlesAndPath(['dolarBundle', 'ipsumBundle'], 'bundles');
        $scopeMather = $this->createMock(ScopeMatcher::class);
        $scopeMather->method('isFrontendRequest')->willReturn(false);

        $instance = $this->createTestInstance([
            'kernel' => $kernel,
            'resource_finder' => $resourceFinder,
            'scope_matcher' => $scopeMather,
        ]);
        $this->assertSame('@ipsum/ce_text.html.twig', $instance->getTemplatePath('ce_text'));

        [$kernel, $resourceFinder] = $this->buildKernelAndResourceFinderForBundlesAndPath(['dolarBundle', 'ipsumBundle'], 'mixed');
        $scopeMather = $this->createMock(ScopeMatcher::class);
        $scopeMather->method('isFrontendRequest')->willReturn(false);

        $instance = $this->createTestInstance([
            'kernel' => $kernel,
            'resource_finder' => $resourceFinder,
            'scope_matcher' => $scopeMather,
        ]);
        $this->assertSame('ce_text.html.twig', $instance->getTemplatePath('ce_text', ['disableCache' => true]));
    }

    protected function buildKernelAndResourceFinderForBundlesAndPath(array $bundles, string $subpath)
    {
        $kernel = $this->createMock(Kernel::class);
        $kernelBundles = [];
        $resourcePaths = [];

        foreach ($bundles as $bundle) {
            $currentBundle = $this->createMock(Bundle::class);
            $bundlePath = __DIR__.'/../Fixtures/templateLocator/'.$subpath.'/'.$bundle.'/src';
            $currentBundle->method('getPath')->willReturn($bundlePath);
            $kernelBundles[$bundle] = $currentBundle;

            if (is_dir($bundlePath.'/Resources/contao')) {
                $resourcePaths[] = $bundlePath.'/Resources/contao';
            }
        }

        $kernel->method('getBundles')->willReturn($kernelBundles);
        $kernel->method('getProjectDir')->willReturn(__DIR__.'/../Fixtures/templateLocator/'.$subpath);

        $resourceFinder = new ResourceFinder($resourcePaths);

        return [$kernel, $resourceFinder];
    }
}