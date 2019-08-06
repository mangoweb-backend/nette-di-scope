<?php declare(strict_types = 1);

namespace Mangoweb\NetteDIScope;

use Nette;


abstract class ScopeExtension extends Nette\DI\CompilerExtension
{
	/** name of service which holds instance of outer container */
	private const OUTER_CONTAINER_SERVICE_NAME = 'outerContainer';

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

		$name = $innerContainerReflection->getName();
		$fileName = $innerContainerReflection->getFileName();
		assert($fileName !== false);

		$this->innerContainerClassName = $name;
		$this->innerContainerPath = $fileName;

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
			'$service->addService(?, $this);',
			'return $service;',
		]);

		$createInnerContainerMethod = $class->getMethod(Nette\DI\Container::getMethodName($this->prefix('container')));
		$createInnerContainerMethod->setBody($code, [$this->innerContainerPath, self::OUTER_CONTAINER_SERVICE_NAME]);
	}


	protected function createInnerConfigurator(): Nette\Configurator
	{
		$configurator = new Nette\Configurator;
		$configurator->defaultExtensions = [
			'extensions' => Nette\DI\Extensions\ExtensionsExtension::class,
		];

		$configurator->onCompile[] = function (Nette\Configurator $configurator, Nette\DI\Compiler $compiler): void {
			$compiler->getContainerBuilder()->addImportedDefinition(self::OUTER_CONTAINER_SERVICE_NAME)
				->setType(Nette\DI\Container::class)
				->setAutowired(false);
		};

		$parameters = $this->getContainerBuilder()->parameters;
		$configurator->addParameters($this->config + [
			'appDir' => $parameters['appDir'],
			'wwwDir' => $parameters['wwwDir'],
			'tempDir' => $parameters['tempDir'],
			'debugMode' => $parameters['debugMode'],
			'productionMode' => $parameters['productionMode'],
			'consoleMode' => $parameters['consoleMode'],
		]);

		return $configurator;
	}
}
