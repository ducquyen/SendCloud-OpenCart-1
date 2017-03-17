<?php
namespace comercia;

class Load
{
    function controller($controller)
    {

        $controllerDir = DIR_APPLICATION . 'controller/';
        $route = $this->getRouteInfo("controller", $controller, $controllerDir);

        $className = $route["class"];
        if(!class_exists($className)) {
            require_once($controllerDir . $route["file"] . ".php");
        }

        $method = $route["method"]?$route["method"]:"index";
        $controller = new $className(Util::registry());
        $result = $controller->$method();
        return $result ? $result : (@$controller->output ? $controller->output : "");
    }


    function library($library)
    {
        $className = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $library))));
        $className = $className;
        $libDir = DIR_SYSTEM . "library/";
        $bestOption = $this->findBestOption($libDir, $library, "php");
        if(!class_exists($className)) {
            require_once($libDir . $bestOption["name"] . ".php");
        }
        return new $className(Util::registry());
    }

    function model($model)
    {
        $modelDir = DIR_APPLICATION . 'model/';
        $route = $this->getRouteInfo("model", $model, $modelDir);
        $className = $route["class"];
        if(!class_exists($className)) {
            require_once($modelDir . $route["file"] . ".php");
        }
        return new $className(Util::registry());
    }

    function view($view, $data = array())
    {

        $bestOption = $this->findBestOption(DIR_TEMPLATE, $view, "tpl");
        $view = $bestOption["name"];


        $registry = Util::registry();
        if (Util::version()->isMinimal("2")) {
            return $registry->get("load")->view($view, $data);
        }
        $fakeControllerFile = __DIR__ . "/fakeController.php";
        require_once($fakeControllerFile);
        $controller = new FakeController($registry);
        return $controller->getView($view, $data);
    }

    function findBestOption($dir, $name, $extension)
    {

        //fiend associated files
        $posibilities = glob($dir . "" . $name . "*." . $extension);
        $files=array();
        foreach ($posibilities as $file) {
            $file = str_replace(DIR_TEMPLATE, "", $file);
            $file = str_replace(".tpl", "", $file);
            $expFile = str_replace(")", "", $file);
            $exp = explode("(", $expFile);
            $files[] = array(
                "name" => $file,
                "version" => isset($exp[1]) ? explode("_", $exp[1]) : false
            );
        }

        //find best option
        $bestOption = false;
        foreach ($files as $file) {
            if (
                ($file["version"]) && //check if this file has a version if no version its never the best option
                (
                    $file["version"][0] == "min" && Util::version()->isMinimal($file["version"][1]) ||//decide if is valid in case of minimal
                    $file["version"][0] == "max" && Util::version()->isMaximal($file["version"][1]) //decide if is valid in case of maximal
                ) &&
                (!$bestOption || $file["version"][0] == "max" || $bestOption["version"][0] == "min") && //prioritize max version over min version
                (
                    !$bestOption || // if there is no best option its always the best option
                    ($file["version"][0] == "min" && version_compare($file["version"][1], $bestOption["version"][1], ">")) ||//if priority is by minimal , find the highest version
                    $file["version"][0] == "max" && version_compare($file["version"][1], $bestOption["version"][1], "<") //if priority is by maximal , find the lowest version
                )
            ) {
                $bestOption = $file;
            }

        }

        if (!$bestOption) {
            $bestOption = array(
                "name" => $name,
                "version" => false,
            );
        }

        return $bestOption;

    }


    function language($file, &$data = array())
    {
        $registry = Util::registry();
        $result = $registry->get("load")->language($file);
        foreach ($result as $key => $val) {
            $data[$key] = $val;
        }
        return $result;
    }

    function getRouteInfo($prefix, $route, $dir)
    {
        $parts = explode('/', preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route));

        $fileRoute = "";
        $method = "";
        while ($parts) {
            $file = $dir . implode('/', $parts) . '.php';

            if (is_file($file)) {
                $fileRoute = implode('/', $parts);
                break;
            } else {
                $method = array_pop($parts);
            }
        }

        $registry = Util::registry();

        $className = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $fileRoute))));
        $className = lcfirst(str_replace(' ', '', ucwords(str_replace('/', ' ', $className))));
        $className = ucfirst($className);
        $className = ucfirst($prefix) . preg_replace('/[^a-zA-Z0-9]/', '', $className);

        $bestOption = $this->findBestOption($dir, $fileRoute, "php");

        return array(
            "file" => $bestOption["name"],
            "class" => $className,
            "method" => $method
        );
    }

    function pageControllers(&$data){
        $data['header'] = Util::load()->controller('common/header');
        $data['column_left'] = Util::load()->controller('common/column_left');
        $data['footer'] = Util::load()->controller('common/footer');
    }
}

?>