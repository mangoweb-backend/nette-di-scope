<?php declare(strict_types = 1);

namespace Mangoweb\NetteDIScope;

use Nette;


abstract class ScopeExtension extends Nette\DI\CompilerExtension
{
	/** @var string */
	private $innerContainerClassName;

	/** @var string */
	private $innerContainerPath;


	abstract public static function getTagName(): string;


	public function loadConfiguration(): void
	{
		parent::loadConfiguration();

		$builder = $this->getContainerBuilder();

		$innerContainer = $this->createInnerConfigurator()->createContainer();
		$innerContainerReflection = new \ReflectionClass($innerContainer);
		$this->innerContainerClassName = $innerContainerReflection->getName();
		$this->innerContainerPath = $innerContainerReflection->getFileName();

		$innerContainerDefinition = $builder->addDefinition($this->prefix('container'));
		$innerContainerDefinition->setType(Nette\DI\Container::class);
		$innerContainerDefinition->setAutowired(false);

		foreach ($innerContainer->findByTag(static::getTagName()) as $serviceName => $tagAttributes) {
			$serviceDef = $builder->addDefinition($this->prefix($serviceName));
			$serviceDef->setType($innerContainer->getServiceType($serviceName));
			$serviceDef->setFactory([$innerContainerDefinition, 'getService'], [$serviceName]);
		}
	}


	public function afterCompile(Nette\PhpGenerator\ClassType $class): void
	{
		parent::afterCompile($class);

		$code = implode("\n", [
			"if (!class_exists({$this->innerContainerClassName}::class, false)) {",
			"\trequire ?;",
			'}',
			'',
			"\$service = new {$this->innerContainerClassName};",
			'$service->addService(\'outerContainer\', $this);',
			'return $service;',
		]);

		$createInnerContainerMethod = $class->getMethod(Nette\DI\Container::getMethodName($this->prefix('container')));
		$createInnerContainerMethod->setBody($code, [$this->innerContainerPath]);
	}


	protected function createInnerConfigurator(): Nette\Configurator
	{
		$configurator = new Nette\Configurator;
		$configurator->defaultExtensions = [
			'extensions' => Nette\DI\Extensions\ExtensionsExtension::class,
		];

		$configurator->onCompile[] = function (Nette\Configurator $configurator, Nette\DI\Compiler $compiler): void {
			$compiler->getContainerBuilder()->addDefinition('outerContainer')
				->setType(Nette\DI\Container::class)
				->setAutowired(false)
				->setDynamic(true);
		};

		$parameters = $this->getContainerBuilder()->parameters;
		$configurator->addParameters([
			'appDir' => $parameters['appDir'],
			'wwwDir' => $parameters['wwwDir'],
			'debugMode' => $parameters['debugMode'],
			'productionMode' => $parameters['productionMode'],
			'consoleMode' => $parameters['consoleMode'],
		]);
		$configurator->setTempDirectory($parameters['tempDir']);

		$configurator->addParameters($this->config);

		return $configurator;
	}
}
