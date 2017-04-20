<?php

namespace APY\DataGridBundle\Grid\Tests;

use APY\DataGridBundle\Grid\Action\MassAction;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Column\Column;
use APY\DataGridBundle\Grid\Column\MassActionColumn;
use APY\DataGridBundle\Grid\Columns;
use APY\DataGridBundle\Grid\Export\ExportInterface;
use APY\DataGridBundle\Grid\Filter;
use APY\DataGridBundle\Grid\Grid;
use APY\DataGridBundle\Grid\GridConfigInterface;
use APY\DataGridBundle\Grid\Helper\ColumnsIterator;
use APY\DataGridBundle\Grid\Row;
use APY\DataGridBundle\Grid\Rows;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Source\Source;
use Symfony\Bundle\FrameworkBundle\Tests\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class GridTest extends TestCase
{
    /**
     * @var Grid
     */
    private $grid;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $router;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $container;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $authChecker;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $request;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $session;

    /**
     * @var string
     */
    private $gridId;

    /**
     * @var string
     */
    private $gridHash;

    public function testInitializeWithoutAnyConfiguration()
    {
        $this->arrange();

        $column = $this->createMock(Column::class);
        $this->grid->addColumn($column);

        $this->grid->initialize();

        $this->assertAttributeEquals(false, 'persistence', $this->grid);
        $this->assertAttributeEmpty('routeParameters', $this->grid);
        $this->assertAttributeEmpty('routeUrl', $this->grid);
        $this->assertAttributeEmpty('source', $this->grid);
        $this->assertAttributeEmpty('defaultOrder', $this->grid);
        $this->assertAttributeEmpty('limits', $this->grid);
        $this->assertAttributeEmpty('maxResults', $this->grid);
        $this->assertAttributeEmpty('page', $this->grid);

        $this->router->expects($this->never())->method($this->anything());
        $column->expects($this->never())->method($this->anything());
    }

    public function testInitializePersistence()
    {
        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('isPersisted')
            ->willReturn(true);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        $this->assertAttributeEquals(true, 'persistence', $this->grid);
    }

    public function testInitializeRouteParams()
    {
        $routeParams = ['foo' => 1, 'bar' => 2];

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getRouteParameters')
            ->willReturn($routeParams);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        $this->assertAttributeEquals($routeParams, 'routeParameters', $this->grid);
    }

    public function testInitializeRouteUrlWithoutParams()
    {
        $route = 'vendor.bundle.controller.route_name';
        $routeParams = ['foo' => 1, 'bar' => 2];
        $url = 'aRandomUrl';

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getRouteParameters')
            ->willReturn($routeParams);
        $gridConfig
            ->method('getRoute')
            ->willReturn($route);

        $this->arrange($gridConfig);

        $this
            ->router
            ->method('generate')
            ->with($route, $routeParams)
            ->willReturn($url);

        $this->grid->initialize();

        $this->assertAttributeEquals($url, 'routeUrl', $this->grid);
    }

    public function testInitializeRouteUrlWithParams()
    {
        $route = 'vendor.bundle.controller.route_name';
        $url = 'aRandomUrl';

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getRoute')
            ->willReturn($route);

        $this->arrange($gridConfig);
        $this
            ->router
            ->method('generate')
            ->with($route, null)
            ->willReturn($url);

        $this->grid->initialize();

        $this->assertAttributeEquals($url, 'routeUrl', $this->grid);
    }

    public function testInizializeColumnsNotFilterableAsGridIsNotFilterable()
    {
        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('isFilterable')
            ->willReturn(false);

        $column = $this->createMock(Column::class);

        $this->arrange($gridConfig);
        $this->grid->addColumn($column);

        $column
            ->expects($this->atLeastOnce())
            ->method('setFilterable')
            ->with(false);

        $this->grid->initialize();
    }

    public function testInizializeColumnsNotSortableAsGridIsNotSortable()
    {
        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('isSortable')
            ->willReturn(false);

        $column = $this->createMock(Column::class);

        $this->arrange($gridConfig);
        $this->grid->addColumn($column);

        $column
            ->expects($this->atLeastOnce())
            ->method('setSortable')
            ->with(false);

        $this->grid->initialize();
    }

    public function testInitializeNotEntitySource()
    {
        $source = $this->createMock(Source::class);

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getSource')
            ->willReturn($source);

        $this->arrange($gridConfig);

        $source
            ->expects($this->once())
            ->method('initialise')
            ->with($this->container);

        $this->grid->initialize();
    }

    public function testInitializeEntitySourceWithoutGroupByFunction()
    {
        $source = $this->createMock(Entity::class);

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getSource')
            ->willReturn($source);

        $this->arrange($gridConfig);

        $source
            ->expects($this->once())
            ->method('initialise')
            ->with($this->container);
        $source
            ->expects($this->never())
            ->method('setGroupBy');

        $this->grid->initialize();
    }

    public function testInitializeEntitySourceWithoutGroupByScalarValue()
    {
        $groupByField = 'groupBy';

        $source = $this->createMock(Entity::class);

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getSource')
            ->willReturn($source);
        $gridConfig
            ->method('getGroupBy')
            ->willReturn($groupByField);

        $this->arrange($gridConfig);

        $source
            ->expects($this->once())
            ->method('initialise')
            ->with($this->container);
        $source
            ->expects($this->atLeastOnce())
            ->method('setGroupBy')
            ->with([$groupByField]);

        $this->grid->initialize();
    }

    public function testInitializeEntitySourceWithoutGroupByArrayValues()
    {
        $groupByArray = ['groupByFoo', 'groupByBar'];

        $source = $this->createMock(Entity::class);

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getSource')
            ->willReturn($source);
        $gridConfig
            ->method('getGroupBy')
            ->willReturn($groupByArray);

        $this->arrange($gridConfig);

        $source
            ->expects($this->once())
            ->method('initialise')
            ->with($this->container);
        $source
            ->expects($this->atLeastOnce())
            ->method('setGroupBy')
            ->with($groupByArray);

        $this->grid->initialize();
    }

    public function testInizializeDefaultOrder()
    {
        $sortBy = 'SORTBY';
        $orderBy = 'ORDERBY';

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getSortBy')
            ->willReturn($sortBy);
        $gridConfig
            ->method('getOrder')
            ->willReturn($orderBy);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        $this->assertAttributeEquals(sprintf('%s|%s', $sortBy, strtolower($orderBy)), 'defaultOrder', $this->grid);
    }

    public function testInizializeDefaultOrderWithoutOrder()
    {
        $sortBy = 'SORTBY';

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getSortBy')
            ->willReturn($sortBy);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        // @todo: is this an admitted case?
        $this->assertAttributeEquals("$sortBy|", 'defaultOrder', $this->grid);
    }

    public function testInizializeLimits()
    {
        $maxPerPage = 10;

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getMaxPerPage')
            ->willReturn($maxPerPage);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        $this->assertAttributeEquals([$maxPerPage => (string) $maxPerPage], 'limits', $this->grid);
    }

    public function testInizializeMaxResults()
    {
        $maxResults = 50;

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getMaxResults')
            ->willReturn($maxResults);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        $this->assertAttributeEquals($maxResults, 'maxResults', $this->grid);
    }

    public function testInizializePage()
    {
        $page = 1;

        $gridConfig = $this->createMock(GridConfigInterface::class);
        $gridConfig
            ->method('getPage')
            ->willReturn($page);

        $this->arrange($gridConfig);

        $this->grid->initialize();

        $this->assertAttributeEquals($page, 'page', $this->grid);
    }

    public function testSetSourceOneThanOneTime()
    {
        $source = $this->createMock(Source::class);

        // @todo maybe this exception should not be \InvalidArgumentException?
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Grid::SOURCE_ALREADY_SETTED_EX_MSG);

        $this->grid->setSource($source);
        $this->grid->setSource($source);
    }

    public function testSetSource()
    {
        $source = $this->createMock(Source::class);

        $source
            ->expects($this->once())
            ->method('initialise')
            ->with($this->container);
        $source
            ->expects($this->once())
            ->method('getColumns')
            ->with($this->isInstanceOf(Columns::class));

        $this->grid->setSource($source);

        $this->assertAttributeEquals($source, 'source', $this->grid);
    }

    public function testGetSource()
    {
        $source = $this->createMock(Source::class);

        $this->grid->setSource($source);

        $this->assertEquals($source, $this->grid->getSource());
    }

    public function testGetNullHashIfNotCreated()
    {
        $this->assertNull($this->grid->getHash());
    }

    public function testHandleRequestRaiseExceptionIfSourceNotSetted()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Grid::SOURCE_NOT_SETTED_EX_MSG);

        $this->grid->handleRequest(
            $this->getMockBuilder(Request::class)
                ->disableOriginalConstructor()
                ->getMock()
        );
    }

    public function testAddColumnToLazyColumnsWithoutPosition()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->grid->addColumn($column);

        $this->assertAttributeEquals([['column' => $column, 'position' => 0]], 'lazyAddColumn', $this->grid);
    }

    public function testAddColumnToLazyColumnsWithPosition()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->grid->addColumn($column, 1);

        $this->assertAttributeEquals([['column' => $column, 'position' => 1]], 'lazyAddColumn', $this->grid);
    }

    public function testAddColumnsToLazyColumnsWithSamePosition()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->grid->addColumn($column1, 1);
        $this->grid->addColumn($column2, 1);

        $this->assertAttributeEquals([
            ['column' => $column1, 'position' => 1],
            ['column' => $column2, 'position' => 1], ],
            'lazyAddColumn',
            $this->grid
        );
    }

    public function testGetColumnFromLazyColumns()
    {
        $columnId = 'foo';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column->method('getId')->willReturn($columnId);

        $this->grid->addColumn($column);

        $this->assertEquals($column, $this->grid->getColumn($columnId));
    }

    public function testGetColumnFromColumns()
    {
        $columnId = 'foo';
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this->createMock(Columns::class);
        $columns
            ->method('getColumnById')
            ->with($columnId)
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $this->assertEquals($column, $this->grid->getColumn($columnId));
    }

    public function testRaiseExceptionIfGetNonExistentColumn()
    {
        $columnId = 'foo';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(Columns::MISSING_COLUMN_EX_MSG, $columnId));

        $this->grid->getColumn($columnId);
    }

    public function testGetColumns()
    {
        $this->assertInstanceOf(Columns::class, $this->grid->getColumns());
    }

    public function testHasColumnInLazyColumns()
    {
        $columnId = 'foo';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column->method('getId')->willReturn($columnId);

        $this->grid->addColumn($column);

        $this->assertTrue($this->grid->hasColumn($columnId));
    }

    public function testHasColumnInColumns()
    {
        $columnId = 'foo';

        $columns = $this->createMock(Columns::class);
        $columns
            ->method('hasColumnById')
            ->with($columnId)
            ->willReturn(true);

        $this->grid->setColumns($columns);

        $this->assertTrue($this->grid->hasColumn($columnId));
    }

    public function testSetColumns()
    {
        $columns = $this->createMock(Columns::class);
        $this->grid->setColumns($columns);

        $this->assertAttributeEquals($columns, 'columns', $this->grid);
    }

    public function testColumnsReorderAndKeepOtherColumns()
    {
        $ids = ['col1', 'col3', 'col2'];

        $columns = $this->createMock(Columns::class);
        $columns
            ->expects($this->once())
            ->method('setColumnsOrder')
            ->with($ids, true);

        $this->grid->setColumns($columns);

        $this->grid->setColumnsOrder($ids, true);
    }

    public function testColumnsReorderAndDontKeepOtherColumns()
    {
        $ids = ['col1', 'col3', 'col2'];

        $columns = $this->createMock(Columns::class);
        $columns
            ->expects($this->once())
            ->method('setColumnsOrder')
            ->with($ids, false);

        $this->grid->setColumns($columns);

        $this->grid->setColumnsOrder($ids, false);
    }

    public function testAddMassActionWithoutRole()
    {
        // @todo: It seems that MassActionInterface does not have getRole in it. is that fine?
        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getRole')
            ->willReturn(null);

        $this->grid->addMassAction($massAction);

        $this->assertAttributeEquals([$massAction], 'massActions', $this->grid);
    }

    public function testAddMassActionWithGrantForActionRole()
    {
        $role = 'aRole';

        // @todo: It seems that MassActionInterface does not have getRole in it. is that fine?
        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getRole')
            ->willReturn($role);

        $this
            ->authChecker
            ->method('isGranted')
            ->with($role)
            ->willReturn(true);

        $this->grid->addMassAction($massAction);

        $this->assertAttributeEquals([$massAction], 'massActions', $this->grid);
    }

    public function testAddMassActionWithoutGrantForActionRole()
    {
        $role = 'aRole';

        // @todo: It seems that MassActionInterface does not have getRole in it. is that fine?
        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getRole')
            ->willReturn($role);

        $this
            ->authChecker
            ->method('isGranted')
            ->with($role)
            ->willReturn(false);

        $this->grid->addMassAction($massAction);

        $this->assertAttributeEmpty('massActions', $this->grid);
    }

    public function testGetMassActions()
    {
        // @todo: It seems that MassActionInterface does not have getRole in it. is that fine?
        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getRole')
            ->willReturn(null);

        $this->grid->addMassAction($massAction);

        $this->assertEquals([$massAction], $this->grid->getMassActions());
    }

    public function testRaiseExceptionIfAddTweakWithNotValidId()
    {
        $tweakId = '#tweakNotValidId';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(Grid::TWEAK_MALFORMED_ID_EX_MSG, $tweakId));

        $this->grid->addTweak('title', [], $tweakId);
    }

    public function testAddTweakWithId()
    {
        $title = 'aTweak';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];
        $id = 'aValidTweakId';
        $group = 'tweakGroup';

        $this->grid->addTweak($title, $tweak, $id, $group);

        $result = [$id => array_merge(['title' => $title, 'id' => $id, 'group' => $group], $tweak)];

        $this->assertAttributeEquals($result, 'tweaks', $this->grid);
    }

    public function testAddTweakWithoutId()
    {
        $title = 'aTweak';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];
        $group = 'tweakGroup';

        $this->grid->addTweak($title, $tweak, null, $group);

        $result = [0 => array_merge(['title' => $title, 'id' => null, 'group' => $group], $tweak)];

        $this->assertAttributeEquals($result, 'tweaks', $this->grid);
    }

    public function testAddRowActionWithoutRole()
    {
        $colId = 'aColId';

        // @todo: It seems that RowActionInterface does not have getRole in it. is that fine?
        $rowAction = $this
            ->getMockBuilder(RowAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $rowAction
            ->method('getRole')
            ->willReturn(null);
        $rowAction
            ->method('getColumn')
            ->willReturn($colId);

        $this->grid->addRowAction($rowAction);

        $this->assertAttributeEquals([$colId => [$rowAction]], 'rowActions', $this->grid);
    }

    public function testAddRowActionWithGrantForActionRole()
    {
        $role = 'aRole';
        $colId = 'aColId';

        // @todo: It seems that MassActionInterface does not have getRole in it. is that fine?
        $rowAction = $this
            ->getMockBuilder(RowAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $rowAction
            ->method('getRole')
            ->willReturn($role);
        $rowAction
            ->method('getColumn')
            ->willReturn($colId);

        $this
            ->authChecker
            ->method('isGranted')
            ->with($role)
            ->willReturn(true);

        $this->grid->addRowAction($rowAction);

        $this->assertAttributeEquals([$colId => [$rowAction]], 'rowActions', $this->grid);
    }

    public function testAddRowActionWithoutGrantForActionRole()
    {
        $role = 'aRole';

        // @todo: It seems that MassActionInterface does not have getRole in it. is that fine?
        $rowAction = $this
            ->getMockBuilder(RowAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $rowAction
            ->method('getRole')
            ->willReturn($role);

        $this
            ->authChecker
            ->method('isGranted')
            ->with($role)
            ->willReturn(false);

        $this->grid->addRowAction($rowAction);

        $this->assertAttributeEmpty('rowActions', $this->grid);
    }

    public function testGetRowActions()
    {
        $colId = 'aColId';

        // @todo: It seems that RowActionInterface does not have getRole in it. is that fine?
        $rowAction = $this
            ->getMockBuilder(RowAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $rowAction
            ->method('getColumn')
            ->willReturn($colId);

        $this->grid->addRowAction($rowAction);

        $this->assertEquals([$colId => [$rowAction]], $this->grid->getRowActions());
    }

    public function testSetExportTwigTemplateInstance()
    {
        $templateName = 'templateName';

        $template = $this
            ->getMockBuilder(\Twig_Template::class)
            ->disableOriginalConstructor()
            ->getMock();
        $template
            ->method('getTemplateName')
            ->willReturn($templateName);

        $result = '__SELF__' . $templateName;

        $this
            ->session
            ->expects($this->once())
            ->method('set')
            ->with($this->anything(), [Grid::REQUEST_QUERY_TEMPLATE => $result]);

        $this->grid->setTemplate($template);

        $this->assertAttributeEquals([Grid::REQUEST_QUERY_TEMPLATE => $result], 'sessionData', $this->grid);
    }

    public function testSetExportStringTemplate()
    {
        $template = 'templateString';

        $this
            ->session
            ->expects($this->once())
            ->method('set')
            ->with($this->anything(), [Grid::REQUEST_QUERY_TEMPLATE => $template]);

        $this->grid->setTemplate($template);

        $this->assertAttributeEquals([Grid::REQUEST_QUERY_TEMPLATE => $template], 'sessionData', $this->grid);
    }

    public function testRaiseExceptionIfSetTemplateWithNoValidValue()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(Grid::TWIG_TEMPLATE_LOAD_EX_MSG);

        $this
            ->session
            ->expects($this->never())
            ->method('set')
            ->with($this->anything(), $this->anything());

        $this->grid->setTemplate(true);

        $this->assertAttributeEquals([], 'sessionData', $this->grid);
    }

    public function testSetExportNullTemplate()
    {
        $this
            ->session
            ->expects($this->never())
            ->method('set')
            ->with($this->anything(), $this->anything());

        $this->grid->setTemplate(null);

        $this->assertAttributeEquals([], 'sessionData', $this->grid);
    }

    public function testReturnTwigTemplate()
    {
        $templateName = 'templateName';

        $template = $this
            ->getMockBuilder(\Twig_Template::class)
            ->disableOriginalConstructor()
            ->getMock();
        $template
            ->method('getTemplateName')
            ->willReturn($templateName);

        $result = '__SELF__' . $templateName;

        $this->grid->setTemplate($template);

        $this->assertEquals($result, $this->grid->getTemplate());
    }

    public function testReturnStringTemplate()
    {
        $template = 'templateString';

        $this->grid->setTemplate($template);

        $this->assertEquals($template, $this->grid->getTemplate());
    }

    public function testAddExportWithoutRole()
    {
        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getRole')
            ->willReturn(null);

        $this->grid->addExport($export);

        $this->assertAttributeEquals([$export], 'exports', $this->grid);
    }

    public function testAddExportWithGrantForActionRole()
    {
        $role = 'aRole';

        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getRole')
            ->willReturn($role);

        $this
            ->authChecker
            ->method('isGranted')
            ->with($role)
            ->willReturn(true);

        $this->grid->addExport($export);

        $this->assertAttributeEquals([$export], 'exports', $this->grid);
    }

    public function testAddExportWithoutGrantForActionRole()
    {
        $role = 'aRole';

        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getRole')
            ->willReturn($role);

        $this
            ->authChecker
            ->method('isGranted')
            ->with($role)
            ->willReturn(false);

        $this->grid->addExport($export);

        $this->assertAttributeEmpty('exports', $this->grid);
    }

    public function testGetExports()
    {
        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getRole')
            ->willReturn(null);

        $this->grid->addExport($export);

        $this->assertEquals([$export], $this->grid->getExports());
    }

    public function testSetRouteParameter()
    {
        $paramName = 'name';
        $paramValue = 'value';

        $otherParamName = 'name';
        $otherParamValue = 'value';

        $this->grid->setRouteParameter($paramName, $paramValue);
        $this->grid->setRouteParameter($otherParamName, $otherParamValue);

        $this->assertAttributeEquals(
            [$paramName => $paramValue, $otherParamName => $otherParamValue],
            'routeParameters',
            $this->grid
        );
    }

    public function testGetRouteParameters()
    {
        $paramName = 'name';
        $paramValue = 'value';

        $otherParamName = 'name';
        $otherParamValue = 'value';

        $this->grid->setRouteParameter($paramName, $paramValue);
        $this->grid->setRouteParameter($otherParamName, $otherParamValue);

        $this->assertEquals(
            [$paramName => $paramValue, $otherParamName => $otherParamValue],
            $this->grid->getRouteParameters()
        );
    }

    public function testSetRouteUrl()
    {
        $url = 'url';

        $this->grid->setRouteUrl($url);

        $this->assertAttributeEquals($url, 'routeUrl', $this->grid);
    }

    public function testGetRouteUrl()
    {
        $url = 'url';

        $this->grid->setRouteUrl($url);

        $this->assertEquals($url, $this->grid->getRouteUrl());
    }

    public function testGetRouteUrlFromRequest()
    {
        $url = 'url';

        $this
            ->request
            ->method('get')
            ->with('_route')
            ->willReturn($url);

        $this
            ->router
            ->method('generate')
            ->with($url, $this->anything())
            ->willReturn($url);

        $this->assertEquals($url, $this->grid->getRouteUrl());
    }

    public function testSetId()
    {
        $id = 'id';
        $this->grid->setId($id);

        $this->assertAttributeEquals($id, 'id', $this->grid);
    }

    public function testGetId()
    {
        $id = 'id';
        $this->grid->setId($id);

        $this->assertEquals($id, $this->grid->getId());
    }

    public function testSetPersistence()
    {
        $this->grid->setPersistence(true);

        $this->assertAttributeEquals(true, 'persistence', $this->grid);
    }

    public function testGetPersistence()
    {
        $this->grid->setPersistence(true);

        $this->assertTrue($this->grid->getPersistence());
    }

    public function testSetDataJunction()
    {
        $this->grid->setDataJunction(Column::DATA_DISJUNCTION);

        $this->assertAttributeEquals(Column::DATA_DISJUNCTION, 'dataJunction', $this->grid);
    }

    public function testGetDataJunction()
    {
        $this->grid->setDataJunction(Column::DATA_DISJUNCTION);

        $this->assertEquals(Column::DATA_DISJUNCTION, $this->grid->getDataJunction());
    }

    public function testSetInvalidLimitsRaiseException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Grid::NOT_VALID_LIMIT_EX_MSG);

        $this->grid->setLimits('foo');
    }

    public function testSetIntLimit()
    {
        $limit = 10;
        $this->grid->setLimits($limit);

        $this->assertAttributeEquals([$limit => (string) $limit], 'limits', $this->grid);
    }

    public function testSetArrayLimits()
    {
        $limits = [10, 50, 100];
        $this->grid->setLimits($limits);

        $this->assertAttributeEquals(array_combine($limits, $limits), 'limits', $this->grid);
    }

    public function testSetAssociativeArrayLimits()
    {
        $limits = [10 => '10', 50 => '50', 100 => '100'];
        $this->grid->setLimits($limits);

        $this->assertAttributeEquals(array_combine($limits, $limits), 'limits', $this->grid);
    }

    public function testGetLimits()
    {
        $limits = [10, 50, 100];
        $this->grid->setLimits($limits);

        $this->assertEquals(array_combine($limits, $limits), $this->grid->getLimits());
    }

    public function testSetDefaultPage()
    {
        $page = 1;
        $this->grid->setDefaultPage($page);

        $this->assertAttributeEquals($page - 1, 'page', $this->grid);
    }

    public function testSetDefaultTweak()
    {
        $tweakId = 1;
        $this->grid->setDefaultTweak($tweakId);

        $this->assertAttributeEquals($tweakId, 'defaultTweak', $this->grid);
    }

    public function testSetPageWithInvalidValueRaiseException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Grid::NOT_VALID_PAGE_NUMBER_EX_MSG);

        $page = '-1';
        $this->grid->setPage($page);
    }

    public function testSetPageWithZeroValue()
    {
        $page = 0;
        $this->grid->setPage($page);

        $this->assertAttributeEquals($page, 'page', $this->grid);
    }

    public function testSetPage()
    {
        $page = 10;
        $this->grid->setPage($page);

        $this->assertAttributeEquals($page, 'page', $this->grid);
    }

    public function testGetPage()
    {
        $page = 10;
        $this->grid->setPage($page);

        $this->assertEquals($page, $this->grid->getPage());
    }

    public function testSetMaxResultWithNullValue()
    {
        $this->grid->setMaxResults();
        $this->assertAttributeEquals(null, 'maxResults', $this->grid);
    }

    public function testSetMaxResultWithInvalidValueRaiseException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Grid::NOT_VALID_MAX_RESULT_EX_MSG);

        $this->grid->setMaxResults(-1);
    }

    // @todo: has this case sense? Should not raise exception?
    public function testSetMaxResultWithStringValue()
    {
        $maxResult = 'foo';
        $this->grid->setMaxResults($maxResult);

        $this->assertAttributeEquals($maxResult, 'maxResults', $this->grid);
    }

    public function testSetMaxResult()
    {
        $maxResult = 1;
        $this->grid->setMaxResults($maxResult);

        $this->assertAttributeEquals($maxResult, 'maxResults', $this->grid);
    }

    public function testIsNotFilteredIfNoColumnIsFiltered()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('isFiltered')
            ->willReturn(false);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('isFiltered')
            ->willReturn(false);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);

        $this->assertFalse($this->grid->isFiltered());
    }

    public function testIsFilteredIfAtLeastAColumnIsFiltered()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('isFiltered')
            ->willReturn(false);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('isFiltered')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);

        $this->assertTrue($this->grid->isFiltered());
    }

    public function testShowTitlesIfAtLeastOneColumnHasATitle()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getTitle')
            ->willReturn(false);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getTitle')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);

        $this->assertTrue($this->grid->isTitleSectionVisible());
    }

    public function testDontShowTitlesIfNoColumnsHasATitle()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getTitle')
            ->willReturn(false);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getTitle')
            ->willReturn(false);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);

        $this->assertFalse($this->grid->isTitleSectionVisible());
    }

    public function testDontShowTitles()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getTitle')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);

        $this->grid->setColumns($columns);

        $this->grid->hideTitles();
        $this->assertFalse($this->grid->isTitleSectionVisible());
    }

    public function testShowFilterSectionIfAtLeastOneColumnFilterable()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('isFilterable')
            ->willReturn(false);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('isFilterable')
            ->willReturn(true);
        $column2
            ->method('getType')
            ->willReturn('text');

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);

        $this->assertTrue($this->grid->isFilterSectionVisible());
    }

    public function testDontShowFilterSectionIfColumnVisibleTypeIsMassAction()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isFilterable')
            ->willReturn(true);
        $column
            ->method('getType')
            ->willReturn('massaction');

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);

        $this->grid->setColumns($columns);

        $this->assertFalse($this->grid->isFilterSectionVisible());
    }

    public function testDontShowFilterSectionIfColumnVisibleTypeIsActions()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isFilterable')
            ->willReturn(true);
        $column
            ->method('getType')
            ->willReturn('actions');

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);

        $this->grid->setColumns($columns);

        $this->assertFalse($this->grid->isFilterSectionVisible());
    }

    public function testDontShowFilterSectionIfNoColumnFilterable()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('isFilterable')
            ->willReturn(false);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('isFilterable')
            ->willReturn(false);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);

        $this->assertFalse($this->grid->isFilterSectionVisible());
    }

    public function testDontShowFilterSection()
    {
        $this->grid->hideFilters();

        $this->assertFalse($this->grid->isFilterSectionVisible());
    }

    public function testHideFilters()
    {
        $this->grid->hideFilters();

        $this->assertAttributeEquals(false, 'showFilters', $this->grid);
    }

    public function testHideTitles()
    {
        $this->grid->hideTitles();

        $this->assertAttributeEquals(false, 'showTitles', $this->grid);
    }

    public function testAddsColumnExtension()
    {
        $extension = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->expects($this->once())
            ->method('addExtension')
            ->with($extension);

        $this->grid->setColumns($columns);

        $this->grid->addColumnExtension($extension);
    }

    public function testSetPrefixTitle()
    {
        $prefixTitle = 'prefixTitle';
        $this->grid->setPrefixTitle($prefixTitle);

        $this->assertAttributeEquals($prefixTitle, 'prefixTitle', $this->grid);
    }

    public function testGetPrefixTitle()
    {
        $prefixTitle = 'prefixTitle';
        $this->grid->setPrefixTitle($prefixTitle);

        $this->assertEquals($prefixTitle, $this->grid->getPrefixTitle());
    }

    public function testSetNoDataMessage()
    {
        $message = 'foo';
        $this->grid->setNoDataMessage($message);

        $this->assertAttributeEquals($message, 'noDataMessage', $this->grid);
    }

    public function testGetNoDataMessage()
    {
        $message = 'foo';
        $this->grid->setNoDataMessage($message);

        $this->assertEquals($message, $this->grid->getNoDataMessage());
    }

    public function testSetNoResultMessage()
    {
        $message = 'foo';
        $this->grid->setNoResultMessage($message);

        $this->assertAttributeEquals($message, 'noResultMessage', $this->grid);
    }

    public function testGetNoResultMessage()
    {
        $message = 'foo';
        $this->grid->setNoResultMessage($message);

        $this->assertEquals($message, $this->grid->getNoResultMessage());
    }

    public function testSetHiddenColumnsWithIntegerId()
    {
        $id = 1;
        $this->grid->setHiddenColumns($id);

        $this->assertAttributeEquals([$id], 'lazyHiddenColumns', $this->grid);
    }

    public function testSetHiddenColumnWithArrayOfIds()
    {
        $ids = [1, 2, 3];
        $this->grid->setHiddenColumns($ids);

        $this->assertAttributeEquals($ids, 'lazyHiddenColumns', $this->grid);
    }

    public function testSetVisibleColumnsWithIntegerId()
    {
        $id = 1;
        $this->grid->setVisibleColumns($id);

        $this->assertAttributeEquals([$id], 'lazyVisibleColumns', $this->grid);
    }

    public function testSetVisibleColumnWithArrayOfIds()
    {
        $ids = [1, 2, 3];
        $this->grid->setVisibleColumns($ids);

        $this->assertAttributeEquals($ids, 'lazyVisibleColumns', $this->grid);
    }

    public function testShowColumnsWithIntegerId()
    {
        $id = 1;
        $this->grid->showColumns($id);

        $this->assertAttributeEquals([$id => true], 'lazyHideShowColumns', $this->grid);
    }

    public function testShowColumnsArrayOfIds()
    {
        $ids = [1, 2, 3];
        $this->grid->showColumns($ids);

        $this->assertAttributeEquals([1 => true, 2 => true, 3 => true], 'lazyHideShowColumns', $this->grid);
    }

    public function testHideColumnsWithIntegerId()
    {
        $id = 1;
        $this->grid->hideColumns($id);

        $this->assertAttributeEquals([$id => false], 'lazyHideShowColumns', $this->grid);
    }

    public function testHideColumnsArrayOfIds()
    {
        $ids = [1, 2, 3];
        $this->grid->hideColumns($ids);

        $this->assertAttributeEquals([1 => false, 2 => false, 3 => false], 'lazyHideShowColumns', $this->grid);
    }

    public function testSetActionsColumnSize()
    {
        $size = 2;
        $this->grid->setActionsColumnSize($size);

        $this->assertAttributeEquals($size, 'actionsColumnSize', $this->grid);
    }

    public function testSetActionsColumnTitle()
    {
        $title = 'aTitle';
        $this->grid->setActionsColumnTitle($title);

        $this->assertAttributeEquals($title, 'actionsColumnTitle', $this->grid);
    }

    public function testClone()
    {
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column1);
        $columns->addColumn($column2);

        $this->grid->setColumns($columns);
        $grid = clone $this->grid;

        $this->assertNotSame($columns, $grid->getColumns());
    }

    public function testRaiseExceptionDuringHandleRequestIfNoSourceSetted()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Grid::SOURCE_NOT_SETTED_EX_MSG);

        $request = $this
            ->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->grid->handleRequest($request);
    }

    public function testCreateHashWithIdDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->handleRequest($this->request);

        $this->assertEquals($this->gridHash, $this->grid->getHash());
    }

    public function testCreateHashWithMd5DuringHandleRequest()
    {
        $this->arrange($this->createMock(GridConfigInterface::class), null);

        $sourceHash = '4f403d7e887f7d443360504a01aaa30e';

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);
        $source
            ->method('getHash')
            ->willReturn($sourceHash);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);
        $this->grid->setColumns($columns);

        $controller = 'aController';

        $this
            ->request
            ->expects($this->at(1))
            ->method('get')
            ->with('_controller')
            ->willReturn($controller);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals('grid_' . md5($controller . $columns->getHash() . $sourceHash), 'hash', $this->grid);
    }

    public function testResetGridSessionWhenChangeGridDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this->getMockBuilder(Column::class)->disableOriginalConstructor()->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->headers
            ->method('get')
            ->with('referer')
            ->willReturn('previousGrid');

        $this
            ->session
            ->expects($this->once())
            ->method('remove')
            ->with($this->gridHash);

        $this->grid->handleRequest($this->request);
    }

    public function testResetGridSessionWhenResetFiltersIsPressedDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('isXmlHttpRequest')
            ->willReturn(true);
        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([Grid::REQUEST_QUERY_RESET => true]);

        $this
            ->request
            ->headers
            ->method('get')
            ->with('referer')
            ->willReturn('aReferer');

        $this
            ->session
            ->expects($this->once())
            ->method('remove')
            ->with($this->gridHash);

        $this->grid->setPersistence(true);

        $this->grid->handleRequest($this->request);
    }

    public function testNotResetGridSessionWhenXmlHttpRequestDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('isXmlHttpRequest')
            ->willReturn(true);

        $this
            ->session
            ->expects($this->never())
            ->method('remove')
            ->with($this->gridHash);

        $this->grid->handleRequest($this->request);
    }

    public function testNotResetGridSessionWhenPersistenceSettedDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('isXmlHttpRequest')
            ->willReturn(true);

        $this
            ->session
            ->expects($this->never())
            ->method('remove')
            ->with($this->gridHash);

        $this->grid->setPersistence(true);

        $this->grid->handleRequest($this->request);
    }

    public function testNotResetGridSessionWhenRefererIsSameGridDuringHandleRequest()
    {
        $scheme = 'http';
        $host = 'www.foo.com/';
        $basUrl = 'baseurl';
        $pathInfo = '/info';

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('isXmlHttpRequest')
            ->willReturn(true);
        $this
            ->request
            ->method('getScheme')
            ->willReturn($scheme);
        $this
            ->request
            ->method('getHttpHost')
            ->willReturn($host);
        $this
            ->request
            ->method('getBaseUrl')
            ->willReturn($basUrl);
        $this
            ->request
            ->method('getPathInfo')
            ->willReturn($pathInfo);

        $this
            ->request
            ->headers
            ->method('get')
            ->with('referer')
            ->willReturn($scheme . '//' . $host . $basUrl . $pathInfo);

        $this
            ->session
            ->expects($this->never())
            ->method('remove')
            ->with($this->gridHash);

        $this->grid->handleRequest($this->request);
    }

    public function testStartNewSessionDuringHandleRequestOnFirstGridRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(true, 'newSession', $this->grid);
    }

    public function testStartKeepSessionDuringHandleRequestNotOnFirstGridRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->session
            ->method('get')
            ->with($this->gridHash)
            ->willReturn('sessionData');

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(false, 'newSession', $this->grid);
    }

    public function testRaiseExceptionIfMassActionIdNotValidDuringHandleRequest()
    {
        $massActionId = 10;

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage(sprintf(Grid::MASS_ACTION_NOT_DEFINED_EX_MSG, $massActionId));

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION => $massActionId,
            ]);

        $this->grid->handleRequest($this->request);
    }

    public function testRaiseExceptionIfMassActionCallbackNotValidDuringHandleRequest()
    {
        $invalidCallback = 'invalidCallback';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf(Grid::MASS_ACTION_CALLBACK_NOT_VALID_EX_MSG, $invalidCallback));

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION => 0,
            ]);

        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getCallback')
            ->willReturn($invalidCallback);

        $this->grid->addMassAction($massAction);

        $this->grid->handleRequest($this->request);
    }

    public function testResetPageAndLimitIfMassActionHandleAllDataDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION                   => 0,
                Grid::REQUEST_QUERY_MASS_ACTION_ALL_KEYS_SELECTED => true,
            ]);

        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getCallback')
            ->willReturn(
                function () { }
            );

        $this->grid->addMassAction($massAction);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
        $this->assertAttributeEquals(0, 'limit', $this->grid);
    }

    public function testMassActionResponseFromCallbackDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $callbackResponse = $this
            ->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION => 0,
            ]);

        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getCallback')
            ->willReturn(
                function () use ($callbackResponse) { return $callbackResponse; }
            );

        $this->grid->addMassAction($massAction);

        $this->grid->handleRequest($this->request);

        $this->assertEquals($callbackResponse, $this->grid->getMassActionResponse());
    }

//    public function testMassActionResponseFromControllerActionDuringHandleRequest()
//    {
//        $row = $this->createMock(Row::class);
//        $rows = new Rows();
//        $rows->addRow($row);
//
//        $source = $this->createMock(Source::class);
//        $source->method('isDataLoaded')->willReturn(true);
//        $source->method('executeFromData')->willReturn($rows);
//        $source->method('getTotalCountFromData')->willReturn(0);
//        $this->grid->setSource($source);
//
//        $column = $this->getMockBuilder(Column::class)->disableOriginalConstructor()->getMock();
//        $column->method('isPrimary')->willReturn(true);
//        $this->grid->addColumn($column);
//
//        $subRequest = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->getMock();
//        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->getMock();
//        $request->method('getSession')->willReturn($this->getMockBuilder(Session::class)->disableOriginalConstructor()->getMock());
//        $request->method('get')->with($this->gridHash)->willReturn([
//            Grid::REQUEST_QUERY_MASS_ACTION => 0,
//        ]);
//        $request->method('duplicate')->willReturn($subRequest);
//        $request->headers = $this->getMockBuilder(HeaderBag::class)->disableOriginalConstructor()->getMock();
//
//        $massAction = $this->getMockBuilder(MassAction::class)->disableOriginalConstructor()->getMock();
//        $massAction->method('getCallback')->willReturn('VendorBundle:Controller:Action');
//        $massAction->method('getParameters')->willReturn(['actionParam' => 1]);
//        $this->grid->addMassAction($massAction);
//
//        $response = $this->getMockBuilder(Response::class)->disableOriginalConstructor()->getMock();
//        $httpKernel = $this->getMockBuilder(HttpKernel::class)->disableOriginalConstructor()->getMock();
//        $httpKernel->method('handle')->with($subRequest, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST)->willReturn($response);
//        $this->container->method('get')->withConsecutive(
//            ['router'], ['request_stack'], ['security.authorization_checker'], ['http_kernel']
//        )->willReturnOnConsecutiveCalls($this->router, $this->requestStack, $this->authChecker, $httpKernel);
//
//        $this->grid->handleRequest($request);
//
//        $this->assertEquals($callbackResponse, $this->grid->getMassActionResponse());
//    }

    public function testMassActionRedirect()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $callbackResponse = $this
            ->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION => 0,
            ]);

        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getCallback')
            ->willReturn(
                function () use ($callbackResponse) { return $callbackResponse; }
            );

        $this->grid->addMassAction($massAction);

        $this->grid->handleRequest($this->request);

        $this->assertTrue($this->grid->isMassActionRedirect());
    }

    public function testRaiseExceptionIfExportIdNotValidDuringHandleRequest()
    {
        $exportId = 10;

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage(sprintf(Grid::EXPORT_NOT_DEFINED_EX_MSG, $exportId));

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_EXPORT => $exportId,
            ]);

        $this->grid->handleRequest($this->request);
    }

    public function testProcessExportsDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_EXPORT => 0,
            ]);

        $response = $this
            ->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();

        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getResponse')
            ->willReturn($response);

        $this->grid->addExport($export);

        $export
            ->expects($this->once())
            ->method('computeData')
            ->with($this->grid);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
        $this->assertAttributeEquals(0, 'limit', $this->grid);
        $this->assertAttributeEquals(true, 'isReadyForExport', $this->grid);
        $this->assertAttributeEquals($response, 'exportResponse', $this->grid);
    }

    public function testProcessPageDuringHandleRequest()
    {
        $row = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_PAGE => 2,
            ]);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(2, 'page', $this->grid);
    }

    public function testProcessPageWithQueryOrderingDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);
        $column
            ->method('getId')
            ->willReturn('order');

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);
        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_ORDER => 'order|foo',
                Grid::REQUEST_QUERY_PAGE  => 2,
            ]);

        $column
            ->expects($this->never())
            ->method('setOrder');

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
    }

    public function testProcessPageWithQueryLimitDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_LIMIT => 50,
            ]);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEmpty('limits', $this->grid);
    }

    public function testProcessPageWithoutQueryLimitDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([]);

        $limits = [10 => '10', 50 => '50'];
        $this->grid->setLimits($limits);
        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals($limits, 'limits', $this->grid);
    }

    public function testProcessPageWithMassActionDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $massAction = $this
            ->getMockBuilder(MassAction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $massAction
            ->method('getCallback')
            ->willReturn(function () { });

        $this->grid->addMassAction($massAction);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION => 0,
                Grid::REQUEST_QUERY_PAGE        => 2,
            ]);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals([Grid::REQUEST_QUERY_PAGE => 0], 'sessionData', $this->grid);
    }

    public function testProcessOrderDescDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $columnId = 'columnId';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);
        $column
            ->method('getId')
            ->willReturn($columnId);
        $column
            ->method('isSortable')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);
        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_ORDER => $columnId . '|desc',
            ]);

        $column
            ->expects($this->once())
            ->method('setOrder')
            ->with('desc');

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
    }

    public function testProcessOrderAscDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $columnId = 'columnId';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);
        $column
            ->method('getId')
            ->willReturn($columnId);
        $column
            ->method('isSortable')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);
        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_ORDER => $columnId . '|asc',
            ]);

        $column
            ->expects($this->once())
            ->method('setOrder')
            ->with('asc');

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
    }

    public function testProcessOrderColumnNotSortableDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $columnId = 'columnId';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);
        $column
            ->method('getId')
            ->willReturn($columnId);
        $column
            ->method('isSortable')
            ->willReturn(false);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);
        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_ORDER => $columnId . '|asc',
            ]);

        $column
            ->expects($this->never())
            ->method('setOrder');

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
    }

    public function testColumnsNotOrderedDuringHandleRequestIfNoOrderRequested()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);
        $column
            ->method('isSortable')
            ->willReturn(true);

        $columns = new Columns($this->authChecker);
        $columns->addColumn($column);
        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([]);

        $column
            ->expects($this->never())
            ->method('setOrder');

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(0, 'page', $this->grid);
    }

    public function testProcessConfiguredLimitDuringHandleRequest()
    {
        $row = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_LIMIT => 10,
            ]);

        $this->grid->setLimits(10);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(10, $this->grid->getLimit());
    }

    public function testProcessNonConfiguredLimitDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_LIMIT => 10,
            ]);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEmpty('limit', $this->grid);
    }

    public function testSetDefaultSessionFiltersDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $col1Id = 'col1';
        $col1FilterValue = 'val1';
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getId')
            ->willReturn($col1Id);

        $this->grid->addColumn($column1);

        $col2Id = 'col2';
        $col2FilterValue = ['val2'];
        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getId')
            ->willReturn($col2Id);

        $this->grid->addColumn($column2);

        $col3Id = 'col3';
        $col3FilterValue = ['from' => true];
        $column3 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column3
            ->method('getId')
            ->willReturn($col3Id);

        $this->grid->addColumn($column3);

        $col4Id = 'col4';
        $col4FilterValue = ['from' => false];
        $column4 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column4
            ->method('getId')
            ->willReturn($col4Id);

        $this->grid->addColumn($column4);

        $col5Id = 'col5';
        $col5FilterValue = ['from' => 'foo', 'to' => 'bar'];
        $column5 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column5
            ->method('getId')
            ->willReturn($col5Id);
        $column5
            ->method('getFilterType')
            ->willReturn('select');

        $this->grid->addColumn($column5);

        $this->grid->setDefaultFilters([
            $col1Id => $col1FilterValue,
            $col2Id => $col2FilterValue,
            $col3Id => $col3FilterValue,
            $col4Id => $col4FilterValue,
            $col5Id => $col5FilterValue,
        ]);

        $column->expects($this->never())->method('setData')->with($this->anything());
        $column1->expects($this->once())->method('setData')->with(['from' => $col1FilterValue]);
        $column2->expects($this->once())->method('setData')->with(['from' => $col2FilterValue]);
        $column3->expects($this->once())->method('setData')->with(['from' => 1]);
        $column4->expects($this->once())->method('setData')->with(['from' => 0]);
        $column5->expects($this->once())->method('setData')->with(['from' => ['foo'], 'to' => ['bar']]);

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultPageRaiseExceptionIfPageHasNegativeValueDuringHandleRequest()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Grid::PAGE_NOT_VALID_EX_MSG);

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $this->grid->setDefaultPage(-1);

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultPageDuringHandleRequest()
    {
        $row = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->setDefaultPage(2);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(1, 'page', $this->grid);
    }

    public function testSetDefaultOrderRaiseExceptionIfOrderNotAscNeitherDescDuringHandleRequest()
    {
        $columnOrder = 'foo';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(Grid::COLUMN_ORDER_NOT_VALID_EX_MSG, $columnOrder));

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('col');

        $this->grid->addColumn($column);

        $this->grid->setDefaultOrder('col', $columnOrder);

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultOrderRaiseExceptionIfColumnDoesNotExistsDuringHandleRequest()
    {
        $colId = 'col';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(Columns::MISSING_COLUMN_EX_MSG, $colId));

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->setDefaultOrder($colId, 'asc');

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultOrderAscDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $columnId = 'columnId';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('columnId');

        $this->grid->addColumn($column);

        $this->grid->setDefaultOrder($columnId, 'asc');

        $column
            ->expects($this->once())
            ->method('setOrder')
            ->with('asc');

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultOrderDescDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $columnId = 'columnId';

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn($columnId);

        $this->grid->addColumn($column);

        $this->grid->setDefaultOrder($columnId, 'desc');

        $column
            ->expects($this->once())
            ->method('setOrder')
            ->with('desc');

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultLimitRaiseExceptionIfLimitIsNotAPositiveDuringHandleRequest()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Grid::DEFAULT_LIMIT_NOT_VALID_EX_MSG);

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $this->grid->setDefaultLimit(-1);

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultLimitRaiseExceptionIfLimitIsNotDefinedInGridLimitsDuringHandleRequest()
    {
        $limit = 2;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(Grid::LIMIT_NOT_DEFINED_EX_MSG, $limit));

        $source = $this->createMock(Source::class);
        $this->grid->setSource($source);

        $this->grid->setDefaultLimit($limit);

        $this->grid->handleRequest($this->request);
    }

    public function testSetDefaultLimitDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->setLimits([2 => '2']);
        $this->grid->setDefaultLimit(2);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(2, 'limit', $this->grid);
    }

    public function testSetPermanentSessionFiltersDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $col1Id = 'col1';
        $col1FilterValue = 'val1';
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getId')
            ->willReturn($col1Id);

        $this->grid->addColumn($column1);

        $col2Id = 'col2';
        $col2FilterValue = ['val2'];
        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getId')
            ->willReturn($col2Id);

        $this->grid->addColumn($column2);

        $col3Id = 'col3';
        $col3FilterValue = ['from' => true];
        $column3 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column3
            ->method('getId')
            ->willReturn($col3Id);

        $this->grid->addColumn($column3);

        $col4Id = 'col4';
        $col4FilterValue = ['from' => false];
        $column4 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column4
            ->method('getId')
            ->willReturn($col4Id);

        $this->grid->addColumn($column4);

        $col5Id = 'col5';
        $col5FilterValue = ['from' => 'foo', 'to' => 'bar'];
        $column5 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column5
            ->method('getId')
            ->willReturn($col5Id);
        $column5
            ->method('getFilterType')
            ->willReturn('select');

        $this->grid->addColumn($column5);

        $this->grid->setPermanentFilters([
            $col1Id => $col1FilterValue,
            $col2Id => $col2FilterValue,
            $col3Id => $col3FilterValue,
            $col4Id => $col4FilterValue,
            $col5Id => $col5FilterValue,
        ]);

        $column->expects($this->never())->method('setData')->with($this->anything());
        $column1->expects($this->once())->method('setData')->with(['from' => $col1FilterValue]);
        $column2->expects($this->once())->method('setData')->with(['from' => $col2FilterValue]);
        $column3->expects($this->once())->method('setData')->with(['from' => 1]);
        $column4->expects($this->once())->method('setData')->with(['from' => 0]);
        $column5->expects($this->once())->method('setData')->with(['from' => ['foo'], 'to' => ['bar']]);

        $this->grid->handleRequest($this->request);
    }

    public function testPrepareRowsFromDataIfDataAlreadyLoadedDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_LIMIT => 10,
            ]);

        $this->grid->setLimits(10);
        $this->grid->setMaxResults(5);

        $source
            ->expects($this->once())
            ->method('executeFromData')
            ->with($columnIterator, 0, 10, 5)
            ->willReturn(new Rows());

        $this->grid->handleRequest($this->request);
    }

    public function testPrepareRowsFromExecutionIfDataNotLoadedDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(false);
        $source
            ->method('getTotalCount')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_LIMIT => 10,
            ]);

        $this->grid->setLimits(10);
        $this->grid->setMaxResults(5);
        $this->grid->setDataJunction(Column::DATA_DISJUNCTION);

        $source
            ->expects($this->once())
            ->method('execute')
            ->with($columnIterator, 0, 10, 5, Column::DATA_DISJUNCTION)
            ->willReturn(new Rows());

        $this->grid->handleRequest($this->request);
    }

    public function testRaiseExceptionIfNotRowInstanceReturnedFromSurceIfDataAlreadyLoadedDuringHandleRequest()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(Grid::NO_ROWS_RETURNED_EX_MSG);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);
    }

    public function testRaiseExceptionIfNotRowInstanceReturnedFromSurceIfDataNotLoadedLoadedDuringHandleRequest()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(Grid::NO_ROWS_RETURNED_EX_MSG);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(false);

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);
    }

    public function testSetFirstPageIfNoRowsFromSourceIfDataAlreadyDataAndRequestedPageNotFirst()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_PAGE => 2,
            ]);

        $source
            ->expects($this->exactly(2))
            ->method('executeFromData')
            ->withConsecutive(
                [$columnIterator, 2, $this->anything(), $this->anything()],
                [$columnIterator, 0, $this->anything(), $this->anything()]
            )
            ->willReturn(new Rows());

        $this->grid->handleRequest($this->request);
    }

    public function testSetFirstPageIfNoRowsFromSourceIfDataNotLoadedAndRequestedPageNotFirst()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(false);
        $source
            ->method('getTotalCount')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_PAGE => 2,
            ]);

        $source
            ->expects($this->exactly(2))
            ->method('execute')
            ->withConsecutive(
                [$columnIterator, 2, $this->anything(), $this->anything(), $this->anything()],
                [$columnIterator, 0, $this->anything(), $this->anything(), $this->anything()]
            )
            ->willReturn(new Rows());

        $this->grid->handleRequest($this->request);
    }

    public function testAddRowActionsToAllColumnsDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);
        $source
            ->expects($this->once())
            ->method('executeFromData')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $actionsColumnId1 = 'actionsColumnId';
        $actionsColumn1 = $this
            ->getMockBuilder(ActionsColumn::class)
            ->disableOriginalConstructor()
            ->getMock();
        $actionsColumn1
            ->method('getId')
            ->willReturn($actionsColumnId1);

        $rowAction1 = new RowAction('title', 'route');
        $rowAction1->setColumn($actionsColumnId1);

        $this->grid->addRowAction($rowAction1);

        $rowAction2 = new RowAction('title', 'route');
        $rowAction2->setColumn($actionsColumnId1);

        $this->grid->addRowAction($rowAction2);

        $actionsColumnId2 = 'actionsColumnId2';
        $actionsColumn2 = $this
            ->getMockBuilder(ActionsColumn::class)
            ->disableOriginalConstructor()
            ->getMock();
        $actionsColumn2
            ->method('getId')
            ->willReturn($actionsColumnId2);

        $rowAction3 = new RowAction('title', 'route');
        $rowAction3->setColumn($actionsColumnId2);

        $this->grid->addRowAction($rowAction3);

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);
        $columns
            ->method('hasColumnById')
            ->withConsecutive([$actionsColumnId1], [$actionsColumnId2])
            ->willReturnOnConsecutiveCalls($actionsColumn1, $actionsColumn2);

        $this->grid->setColumns($columns);

        $actionsColumn1
            ->expects($this->once())
            ->method('setRowActions')
            ->with([$rowAction1, $rowAction2]);

        $actionsColumn2
            ->expects($this->once())
            ->method('setRowActions')
            ->with([$rowAction3]);

        $this->grid->handleRequest($this->request);
    }

    public function testAddRowActionsToNotExistingColumnDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);
        $source
            ->expects($this->once())
            ->method('executeFromData')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $actionsColumnId1 = 'actionsColumnId';

        $rowAction1 = new RowAction('title', 'route');
        $rowAction1->setColumn($actionsColumnId1);

        $this->grid->addRowAction($rowAction1);

        $actionsColumnId2 = 'actionsColumnId2';

        $rowAction2 = new RowAction('title', 'route');
        $rowAction2->setColumn($actionsColumnId2);

        $this->grid->addRowAction($rowAction2);

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $actionsColumnTitle = 'aTitle';
        $this->grid->setActionsColumnTitle($actionsColumnTitle);

        $missingActionsColumn1 = new ActionsColumn($actionsColumnId1, $actionsColumnTitle, [$rowAction1]);
        $missingActionsColumn2 = new ActionsColumn($actionsColumnId2, $actionsColumnTitle, [$rowAction2]);

        $columns
            ->expects($this->exactly(2))
            ->method('addColumn')
            ->withConsecutive([$missingActionsColumn1], [$missingActionsColumn2]);

        $this->grid->handleRequest($this->request);
    }

    public function testAddMassActionColumnsDuringHandleRequest()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);
        $source
            ->expects($this->once())
            ->method('executeFromData')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $this->grid->addMassAction(new MassAction('title'));

        $columns
            ->expects($this->once())
            ->method('addColumn')
            ->with($this->isInstanceOf(MassActionColumn::class), 1);

        $this->grid->handleRequest($this->request);
    }

    public function testSetPrimaryFieldOnEachRow()
    {
        $row = $this->createMock(Row::class);
        $row2 = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);
        $rows->addRow($row2);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);
        $source
            ->method('executeFromData')
            ->willReturn($rows);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $row
            ->expects($this->once())
            ->method('setPrimaryField')
            ->with('primaryID');

        $row2
            ->expects($this->once())
            ->method('setPrimaryField')
            ->with('primaryID');

        $this->grid->handleRequest($this->request);
    }

    public function testPopulateSelectFiltersInSourceFromDataIfDataLoadedDuringHandleRequest()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->expects($this->once())
            ->method('populateSelectFiltersFromData')
            ->with($columns);

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);
    }

    public function testPopulateSelectFiltersInSourceIfDataNotLoadedDuringHandleRequest()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(false);
        $source
            ->method('getTotalCount')
            ->willReturn(0);
        $source
            ->method('execute')
            ->willReturn(new Rows());
        $source
            ->expects($this->once())
            ->method('populateSelectFilters')
            ->with($columns);

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);
    }

    public function testSetTotalCountFromDataDuringHandleRequest()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(2);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(2, 'totalCount', $this->grid);
    }

    public function testSetTotalCountDuringHandleRequest()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(false);
        $source
            ->method('getTotalCount')
            ->willReturn(2);
        $source
            ->method('execute')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);

        $this->assertAttributeEquals(2, 'totalCount', $this->grid);
    }

    public function testThrowsExceptionIfTotalCountNotIntegerFromDataDuringHandleRequest()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(sprintf(Grid::INVALID_TOTAL_COUNT_EX_MSG, 'NULL'));

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);
    }

    public function testThrowsExceptionIfTotalCountNotIntegerDuringHandleRequest()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(sprintf(Grid::INVALID_TOTAL_COUNT_EX_MSG, 'NULL'));

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(false);
        $source
            ->method('execute')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);
    }

    public function testSaveSessionDuringHandleRequest()
    {
        // @todo: split in more than one test if needed
    }

    public function testProcessTweakResetDuringHandleRequest()
    {
        $row = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $title = 'aTweak';
        $tweak = ['reset' => 1];
        $tweakId = 'aValidTweakId';

        $this->grid->addTweak($title, $tweak, $tweakId);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_TWEAK => $tweakId,
            ]);

        $this
            ->session
            ->expects($this->atLeastOnce())
            ->method('remove')
            ->with($this->gridHash);

        $this->grid->handleRequest($this->request);
    }

    public function testProcessTweakFiltersDuringHandleRequest()
    {
        $row = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $colId = 'colId';
        $colFilter = ['from' => 'foo', 'to' => 'bar'];
        $column
            ->method('getId')
            ->willReturn($colId);
        $column
            ->method('getFilterType')
            ->willReturn('select');

        $this->grid->addColumn($column);

        $title = 'aTweak';
        $tweak = ['filters' => [$colId => $colFilter]];
        $tweakId = 'aValidTweakId';

        $this->grid->addTweak($title, $tweak, $tweakId);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_TWEAK => $tweakId,
            ]);

        $this
            ->session
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->gridHash, ['tweaks' => ['' => $tweakId], $colId => ['from' => ['foo'], 'to' => ['bar']]]);

        $this->grid->handleRequest($this->request);
    }

    public function testProcessTweakOrderDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessTweakMassActionDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessTweakPageDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessTweakLimitDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessTweakExportDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessRemoveActiveTweakGroupsDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessRemoveActiveTweakDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessAddActiveTweakDuringHandleRequest()
    {
        // @todo
    }

    public function testProcessTweaksAndStopOtherProcessing()
    {
        // @todo
    }

    public function testProcessPageWithFiltersDuringHandleRequest()
    {
        // @todo: split in more than one test if needed
    }

    public function testProcessDefaultTweaksDuringHandleRequest()
    {
        // @todo: split in more than one test if needed
    }

    public function testIsReadyForRedirect()
    {
        // @todo: split in more than one test if needed
    }

    public function testGetTweaksWithUrlWithoutGetParameters()
    {
        $routeUrl = 'http://www.foo.com';

        $title = 'aTweak';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];
        $id = 'aValidTweakId';
        $group = 'tweakGroup';
        $tweakUrl = sprintf('%s?[%s]=%s', $routeUrl, Grid::REQUEST_QUERY_TWEAK, $id);

        $this->grid->addTweak($title, $tweak, $id, $group);

        $title2 = 'aTweak';
        $tweak2 = ['filters' => [], 'order' => 'columnId2', 'page' => 2, 'limit' => 100, 'export' => 0, 'massAction' => 0];
        $id2 = 'aValidTweakId2';
        $group2 = 'tweakGroup2';
        $tweakUrl2 = sprintf('%s?[%s]=%s', $routeUrl, Grid::REQUEST_QUERY_TWEAK, $id2);

        $this->grid->setRouteUrl($routeUrl);

        $this->grid->addTweak($title2, $tweak2, $id2, $group2);

        $result = [
            $id  => array_merge(['title' => $title, 'id' => $id, 'group' => $group, 'url' => $tweakUrl], $tweak),
            $id2 => array_merge(['title' => $title2, 'id' => $id2, 'group' => $group2, 'url' => $tweakUrl2], $tweak2),
        ];

        $this->assertEquals($result, $this->grid->getTweaks());
    }

    public function testGetTweaksWithUrlWithGetParameters()
    {
        $routeUrl = 'http://www.foo.com?foo=foo';

        $title = 'aTweak';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];
        $id = 'aValidTweakId';
        $group = 'tweakGroup';
        $tweakUrl = sprintf('%s&[%s]=%s', $routeUrl, Grid::REQUEST_QUERY_TWEAK, $id);

        $this->grid->addTweak($title, $tweak, $id, $group);

        $title2 = 'aTweak';
        $tweak2 = ['filters' => [], 'order' => 'columnId2', 'page' => 2, 'limit' => 100, 'export' => 0, 'massAction' => 0];
        $id2 = 'aValidTweakId2';
        $group2 = 'tweakGroup2';
        $tweakUrl2 = sprintf('%s&[%s]=%s', $routeUrl, Grid::REQUEST_QUERY_TWEAK, $id2);

        $this->grid->setRouteUrl($routeUrl);

        $this->grid->addTweak($title2, $tweak2, $id2, $group2);

        $result = [
            $id  => array_merge(['title' => $title, 'id' => $id, 'group' => $group, 'url' => $tweakUrl], $tweak),
            $id2 => array_merge(['title' => $title2, 'id' => $id2, 'group' => $group2, 'url' => $tweakUrl2], $tweak2),
        ];

        $this->assertEquals($result, $this->grid->getTweaks());
    }

    public function testRaiseExceptionIfGetNonExistentTweak()
    {
        $nonExistentTweak = 'aNonExistentTweak';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(Grid::NOT_VALID_TWEAK_ID_EX_MSG, $nonExistentTweak));

        $tweakId = 'aValidTweakId';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];

        $this->grid->addTweak('title', $tweak, $tweakId, 'group');

        $this->grid->getTweak($nonExistentTweak);
    }

    public function testGetTweak()
    {
        $title = 'aTweak';
        $id = 'aValidTweakId';
        $group = 'tweakGroup';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];
        $tweakUrl = sprintf('?[%s]=%s', Grid::REQUEST_QUERY_TWEAK, $id);

        $this->grid->addTweak($title, $tweak, $id, $group);

        $tweakResult = array_merge(['title' => $title, 'id' => $id, 'group' => $group, 'url' => $tweakUrl], $tweak);

        $this->assertEquals($tweakResult, $this->grid->getTweak($id));
    }

    public function testGetTweaksByGroupExcludingThoseWhoDoNotHaveTheGroup()
    {
        $title = 'aTweak';
        $id = 'aValidTweakId';
        $group = 'tweakGroup';
        $tweak = ['filters' => [], 'order' => 'columnId', 'page' => 1, 'limit' => 50, 'export' => 1, 'massAction' => 1];
        $tweakUrl = sprintf('?[%s]=%s', Grid::REQUEST_QUERY_TWEAK, $id);
        $tweakResult = [$id => array_merge(['title' => $title, 'id' => $id, 'group' => $group, 'url' => $tweakUrl], $tweak)];

        $this->grid->addTweak($title, $tweak, $id, $group);

        $tweak2 = ['filters' => [], 'order' => 'columnId', 'page' => 2, 'limit' => 100, 'export' => 0, 'massAction' => 0];

        $this->grid->addTweak('aTweak2', $tweak2, 'aValidTweakId2', 'tweakGroup2');

        $this->assertEquals($tweakResult, $this->grid->getTweaksGroup($group));
    }

    public function testGetActiveTweaks()
    {
        // @todo split in more than one test if needed
    }

    public function testGetActiveTweakGroup()
    {
        // @todo split in more than one test if needed
    }

    public function testGetExportResponse()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_EXPORT => 0,
            ]);

        $response = $this
            ->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();

        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getResponse')
            ->willReturn($response);

        $this->grid->addExport($export);

        $this->grid->handleRequest($this->request);

        $this->assertEquals($response, $this->grid->getExportResponse());
    }

    public function testIsReadyForExport()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_EXPORT => 0,
            ]);

        $response = $this
            ->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();

        $export = $this->createMock(ExportInterface::class);
        $export
            ->method('getResponse')
            ->willReturn($response);

        $this->grid->addExport($export);

        $this->grid->handleRequest($this->request);

        $this->assertTrue($this->grid->isReadyForExport());
    }

    public function testSetPermanentFilters()
    {
        $filters = [
            'colId1' => 'value',
            'colId2' => 'value',
        ];

        $this->grid->setPermanentFilters($filters);

        $this->assertAttributeEquals($filters, 'permanentFilters', $this->grid);
    }

    public function testSetDefaultFilters()
    {
        $filters = [
            'colId1' => 'value',
            'colId2' => 'value',
        ];

        $this->grid->setDefaultFilters($filters);

        $this->assertAttributeEquals($filters, 'defaultFilters', $this->grid);
    }

    public function testSetDefaultOrder()
    {
        $colId = 'COLID';
        $order = 'ASC';

        $this->grid->setDefaultOrder($colId, $order);

        $this->assertAttributeEquals(sprintf("$colId|%s", strtolower($order)), 'defaultOrder', $this->grid);
    }

    public function testGetRows()
    {
        $row = $this->createMock(Row::class);
        $rows = new Rows();
        $rows->addRow($row);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->handleRequest($this->request);

        $this->assertEquals($rows, $this->grid->getRows());
    }

    public function testGetTotalCount()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(20);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(20, $this->grid->getTotalCount());
    }

    public function testGetPageCountWithoutLimit()
    {
        $this->assertEquals(1, $this->grid->getPageCount());
    }

    public function testGetPageCount()
    {
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('primaryID');

        $columnIterator = $this
            ->getMockBuilder(ColumnsIterator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $columns = $this
            ->getMockBuilder(Columns::class)
            ->disableOriginalConstructor()
            ->getMock();
        $columns
            ->method('getIterator')
            ->willReturn($columnIterator);
        $columns
            ->method('getPrimaryColumn')
            ->willReturn($column);

        $this->grid->setColumns($columns);

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(29);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());

        $this->grid->setSource($source);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_LIMIT => 10,
            ]);

        $this->grid->setLimits(10);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(3, $this->grid->getPageCount());
    }

    public function testIsPagerSectionNotVisibleWhenNoLimitsSetted()
    {
        $this->assertFalse($this->grid->isPagerSectionVisible());
    }

    public function testIsPagerSectionNotVisibleWhenSmallestLimitGreaterThanTotalCount()
    {
        $this->grid->setLimits([10, 20, 30]);

        $this->assertFalse($this->grid->isPagerSectionVisible());
    }

    public function testIsPagerSectionVisibleWhenSmallestLimitLowestThanTotalCount()
    {
        $this->grid->setLimits([10, 20, 30]);

        $this->assertFalse($this->grid->isPagerSectionVisible());
    }

    public function testDeleteAction()
    {
        $source = $this->createMock(Source::class);

        $this->grid->setSource($source);

        $deleteIds = [1, 2, 3];
        $source
            ->expects($this->once())
            ->method('delete')
            ->with($deleteIds);

        $this->grid->deleteAction($deleteIds);
    }

    public function testGetGridResponse()
    {
        // @todo split in more than one test if needed
    }

    public function testGetRawDataWithAllColumnsIfNoColumnsRequested()
    {
        $rows = new Rows();

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $col1Id = 'col1Id';
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getId')
            ->willReturn($col1Id);
        $column1
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column1);

        $col2Id = 'col2Id';
        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getId')
            ->willReturn($col2Id);

        $this->grid->addColumn($column2);

        $rowCol1Field = 'rowCol1Field';
        $rowCol2Field = 'rowCol2Field';
        $row = $this->createMock(Row::class);
        $row
            ->method('getField')
            ->withConsecutive([$col1Id], [$col2Id])
            ->willReturnOnConsecutiveCalls($rowCol1Field, $rowCol2Field);

        $rows->addRow($row);

        $row2Col1Field = 'row2Col1Field';
        $row2Col2Field = 'row2Col2Field';
        $row2 = $this->createMock(Row::class);
        $row2
            ->method('getField')
            ->withConsecutive([$col1Id], [$col2Id])
            ->willReturnOnConsecutiveCalls($row2Col1Field, $row2Col2Field);

        $rows->addRow($row2);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(
            [
                [$col1Id => $rowCol1Field, $col2Id => $rowCol2Field],
                [$col1Id => $row2Col1Field, $col2Id => $row2Col2Field],
            ],
            $this->grid->getRawData()
        );
    }

    public function testGetRawDataWithSubsetOfColumns()
    {
        $rows = new Rows();

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $col1Id = 'col1Id';
        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getId')
            ->willReturn($col1Id);
        $column1
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column1);

        $col2Id = 'col2Id';
        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getId')
            ->willReturn($col2Id);

        $this->grid->addColumn($column2);

        $rowCol1Field = 'rowCol1Field';
        $rowCol2Field = 'rowCol2Field';
        $row = $this->createMock(Row::class);
        $row
            ->method('getField')
            ->withConsecutive([$col1Id], [$col2Id])
            ->willReturnOnConsecutiveCalls($rowCol1Field, $rowCol2Field);

        $rows->addRow($row);

        $row2Col1Field = 'row2Col1Field';
        $row2Col2Field = 'row2Col2Field';
        $row2 = $this->createMock(Row::class);
        $row2
            ->method('getField')
            ->withConsecutive([$col1Id], [$col2Id])
            ->willReturnOnConsecutiveCalls($row2Col1Field, $row2Col2Field);

        $rows->addRow($row2);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(
            [
                [$col1Id => $rowCol1Field],
                [$col1Id => $row2Col1Field],
            ],
            $this->grid->getRawData($col1Id)
        );
    }

    public function testGetRawDataWithoutNamedIndexesResult()
    {
        $rows = new Rows();

        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn($rows);
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $colId = 'colId';
        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn($colId);
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $rowColField = 'rowColField';
        $row = $this->createMock(Row::class);
        $row
            ->method('getField')
            ->with($colId)
            ->willReturn($rowColField);

        $rows->addRow($row);

        $row2ColField = 'row2ColField';
        $row2 = $this->createMock(Row::class);
        $row2
            ->method('getField')
            ->with($colId)
            ->willReturn($row2ColField);

        $rows->addRow($row2);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(
            [
                [$rowColField],
                [$row2ColField],
            ],
            $this->grid->getRawData($colId, false)
        );
    }

    public function testGetFiltersRaiseExceptionIfNoRequestProcessed()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(Grid::GET_FILTERS_NO_REQUEST_HANDLED_EX_MSG);

        $this->grid->getFilters();
    }

    public function testGetFilters()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $column1 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column1
            ->method('getId')
            ->willReturn('col1Id');

        $this->grid->addColumn($column1);

        $column2 = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column2
            ->method('getId')
            ->willReturn('col2Id');
        $column2
            ->method('getDefaultOperator')
            ->willReturn(Column::OPERATOR_GT);

        $this->grid->addColumn($column2);

        $this
            ->request
            ->method('get')
            ->with($this->gridHash)
            ->willReturn([
                Grid::REQUEST_QUERY_MASS_ACTION_ALL_KEYS_SELECTED => true,
                Grid::REQUEST_QUERY_MASS_ACTION                   => true,
                Grid::REQUEST_QUERY_EXPORT                        => false,
                Grid::REQUEST_QUERY_PAGE                          => 1,
                Grid::REQUEST_QUERY_LIMIT                         => 10,
                Grid::REQUEST_QUERY_ORDER                         => null,
                Grid::REQUEST_QUERY_TEMPLATE                      => 'aTemplate',
                Grid::REQUEST_QUERY_RESET                         => false,
                MassActionColumn::ID                              => 'massActionColId',
            ]);

        $col1Id = 'col1Id';
        $filter1Operator = Column::OPERATOR_BTW;
        $filter1From = 'from1';
        $filter1To = 'to1';
        $filter1 = new Filter($filter1Operator, ['from' => $filter1From, 'to' => $filter1To]);

        $col2Id = 'col2Id';
        $filter2Operator = Column::OPERATOR_GT;
        $filter2From = 'from2';
        $filter2 = new Filter($filter2Operator, $filter2From);

        $this->grid->setDefaultFilters([
            $col1Id => ['operator' => $filter1Operator, 'from' => $filter1From, 'to' => $filter1To],
            $col2Id => ['operator' => $filter2Operator, 'from' => $filter2From],
        ]);

        $this->grid->handleRequest($this->request);

        $this->assertEquals(
            [$col1Id => $filter1, $col2Id => $filter2],
            $this->grid->getFilters()
        );
    }

    public function testGetFilterRaiseExceptionIfNoRequestProcessed()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(Grid::GET_FILTERS_NO_REQUEST_HANDLED_EX_MSG);

        $this->grid->getFilter('foo');
    }

    public function testGetFilterReturnNullIfRequestedColumnHasNoFilter()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->handleRequest($this->request);

        $this->assertNull($this->grid->getFilter('foo'));
    }

    public function testGetFilter()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('col1Id');

        $this->grid->addColumn($column);

        $colId = 'col1Id';
        $filterOperator = Column::OPERATOR_BTW;
        $filterFrom = 'from1';
        $filterTo = 'to1';
        $filter = new Filter($filterOperator, ['from' => $filterFrom, 'to' => $filterTo]);

        $this->grid->setDefaultFilters([
            $colId => ['operator' => $filterOperator, 'from' => $filterFrom, 'to' => $filterTo],
        ]);

        $this->grid->handleRequest($this->request);

        $this->assertEquals($filter, $this->grid->getFilter($colId));
    }

    public function testHasFilterRaiseExceptionIfNoRequestProcessed()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(Grid::HAS_FILTER_NO_REQUEST_HANDLED_EX_MSG);

        $this->grid->hasFilter('foo');
    }

    public function testHasFilterReturnNullIfRequestedColumnHasNoFilter()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $this->grid->handleRequest($this->request);

        $this->assertFalse($this->grid->hasFilter('foo'));
    }

    public function testHasFilter()
    {
        $source = $this->createMock(Source::class);
        $source
            ->method('isDataLoaded')
            ->willReturn(true);
        $source
            ->method('executeFromData')
            ->willReturn(new Rows());
        $source
            ->method('getTotalCountFromData')
            ->willReturn(0);

        $this->grid->setSource($source);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('isPrimary')
            ->willReturn(true);

        $this->grid->addColumn($column);

        $column = $this
            ->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column
            ->method('getId')
            ->willReturn('col1Id');

        $this->grid->addColumn($column);

        $colId = 'col1Id';
        $filterOperator = Column::OPERATOR_BTW;
        $filterFrom = 'from1';
        $filterTo = 'to1';

        $this->grid->setDefaultFilters([
            $colId => ['operator' => $filterOperator, 'from' => $filterFrom, 'to' => $filterTo],
        ]);

        $this->grid->handleRequest($this->request);

        $this->assertTrue($this->grid->hasFilter($colId));
    }

    public function setUp()
    {
        $this->arrange($this->createMock(GridConfigInterface::class));
    }

    /**
     * @param $gridConfigInterface
     * @param string $id
     */
    private function arrange($gridConfigInterface = null, $id = 'id')
    {
        $session = $this
            ->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->session = $session;

        $request = $this
            ->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();
        $request
            ->method('getSession')
            ->willReturn($session);
        $request->headers = $this
            ->getMockBuilder(HeaderBag::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->request = $request;

        $request->attributes = new ParameterBag([]);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack
            ->method('getCurrentRequest')
            ->willReturn($request);
        $this->requestStack = $requestStack;

        $this->router = $this
            ->getMockBuilder(Router::class)
            ->disableOriginalConstructor()
            ->getMock();

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authChecker = $authChecker;

        $container = $this
            ->getMockBuilder(Container::class)
            ->disableOriginalConstructor()
            ->getMock();
        $container
            ->method('get')
            ->withConsecutive(
                ['router'], ['request_stack'], ['security.authorization_checker']
        )->willReturnOnConsecutiveCalls($this->router, $requestStack, $authChecker);
        $this->container = $container;

        $this->gridId = $id;
        $this->gridHash = 'grid_' . $this->gridId;

        $this->grid = new Grid($container, $this->gridId, $gridConfigInterface);
    }
}
