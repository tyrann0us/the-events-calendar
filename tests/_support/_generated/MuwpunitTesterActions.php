<?php  //[STAMP] 473fbaa359f67688bd75809fd344fb94
namespace _generated;

// This class was automatically generated by build task
// You should not change it manually as it will be overwritten on next build
// @codingStandardsIgnoreFile

use Helper\Muwpunit;
use Codeception\Module\WPLoader;

trait MuwpunitTesterActions
{
    /**
     * @return \Codeception\Scenario
     */
    abstract protected function getScenario();

    
    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     * Calls a list of user-defined actions needed in tests.
     * @see \Codeception\Module\WPLoader::bootstrapActions()
     */
    public function bootstrapActions() {
        return $this->getScenario()->runStep(new \Codeception\Step\Action('bootstrapActions', func_get_args()));
    }

 
    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     *
     * @see \Codeception\Module\WPLoader::activatePlugins()
     */
    public function activatePlugins() {
        return $this->getScenario()->runStep(new \Codeception\Step\Action('activatePlugins', func_get_args()));
    }

 
    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     * Loads the plugins required by the test.
     * @see \Codeception\Module\WPLoader::loadPlugins()
     */
    public function loadPlugins() {
        return $this->getScenario()->runStep(new \Codeception\Step\Action('loadPlugins', func_get_args()));
    }

 
    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     * Accessor method to get the object storing the factories for things.
	 *
	 * Example usage:
	 *
	 *        $postId = $I->factory()->post->create();
	 *
	 * @return \tad\WPBrowser\Module\WPLoader\FactoryStore
     * @see \Codeception\Module\WPLoader::factory()
     */
    public function factory() {
        return $this->getScenario()->runStep(new \Codeception\Step\Action('factory', func_get_args()));
    }
}
