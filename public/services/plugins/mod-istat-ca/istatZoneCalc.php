<?php

require_once('../../../../config/config.php');
require_once (ROOT_PATH.'lib/functions.php');
require_once(ROOT_PATH.'lib/gcPgQuery.class.php');//Definizione dell'oggetto PgQuery
require_once ROOT_PATH . 'lib/GCService.php';

$gcService = GCService::instance();
$gcService->startSession();

if (isset($_REQUEST['export'])) {
    if ($_REQUEST['export'] == 'shp') {
        $filenameBase = 'istatpolygon_'.date('YmdHi').rand(0,999);
        $filenameXLS = GC_WEB_TMP_DIR.$filenameBase.'.csv';
        $filenameSHP = GC_WEB_TMP_DIR.$filenameBase.'.shp';
        $urlSHP = GC_WEB_TMP_URL.$filenameBase.'.zip';
        $logFile = GC_WEB_TMP_DIR.$filenameBase.'.log';
        $xlsContent = "gid;wkt\n1;" . $_REQUEST['sGeom'] . "\n";
        file_put_contents($filenameXLS, $xlsContent);
        $command = 'ogr2ogr -f "ESRI Shapefile" ' . $filenameSHP . ' -dialect sqlite -sql "SELECT gid, GeomFromText(WKT) as geom FROM '.$filenameBase.'" ' . $filenameXLS . ' -a_srs ' . $_REQUEST['srid'];
        system($command.">$logFile 2>&1", $retVal);
        if($retVal !== 0) {
			 die(json_encode(array('result'=>'ko', 'message' => 'Errore in creazione Shapefile', 'file'=>$logFile)));
		}

        $zipSHP = new ZipArchive();
        $filenameZip = GC_WEB_TMP_DIR.$filenameBase.'.zip';
        if ($zipSHP->open($filenameZip, ZIPARCHIVE::CREATE) !== TRUE) {
            die(json_encode(array('result'=>'ko', 'message' => 'Errore in creazione file ZIP')));
        }
        $zipSHP->addFile($filenameSHP, $filenameBase.'.shp');
        $zipSHP->addFile(GC_WEB_TMP_DIR.$filenameBase.'.shx', $filenameBase.'.shx');
        $zipSHP->addFile(GC_WEB_TMP_DIR.$filenameBase.'.dbf', $filenameBase.'.dbf');
        $zipSHP->addFile(GC_WEB_TMP_DIR.$filenameBase.'.prj', $filenameBase.'.prj');
        $zipSHP->close();

        die(json_encode(array('result'=>'ok',command=>$command,'file'=>$urlSHP)));
        ini_set('display_errors', 'On');
        error_reporting(E_ALL);
    }
}

$db = GCApp::getDB();

$request = $_REQUEST;

$sql = 'select layer_id from '.DB_SCHEMA.'.layer
    inner join '.DB_SCHEMA.'.layergroup on layer.layergroup_id = layergroup.layergroup_id
    inner join '.DB_SCHEMA.'.mapset_layergroup on mapset_layergroup.layergroup_id = layergroup.layergroup_id
    where mapset_name = :mapset_name and layergroup_name = :layergroup_name and layer_name = :layer_name';
$stmt = $db->prepare($sql);

list($layergroupName, $layerName) = explode('.', $_REQUEST['featureType']);

$stmt->execute(array(
    'mapset_name'=>$_REQUEST['mapsetName'],
    'layergroup_name'=>$layergroupName,
    'layer_name'=>$layerName
));

$request['layer_id'] = $stmt->fetchColumn(0);

$oQuery = new PgQuery($request);

$aTemplate = $oQuery->templates[$request['layer_id']];
$dataDB = GCApp::getDataDB($aTemplate['catalog_path']);
$datalayerSchema = GCApp::getDataDBSchema($aTemplate['catalog_path']);
$aTemplate['table_schema'] = $datalayerSchema;
$aTemplate['fields'] = $aTemplate['field'];
$options = array('include_1n_relations'=>true);
$requestSRID = str_replace('EPSG:', '', $request['srid']);
$dataSRID = $aTemplate['data_srid'];
//if(!empty($request['srid'])) $options['srid'] = $request['srid'];

$addFields = "ST_Intersection(". $aTemplate["data_geom"] . ",ST_Transform(ST_GeomFromText('" . $request['sGeom'] . "',$requestSRID)," . $dataSRID . ")) as ca_geom";
$caMainQuery = GCAuthor::buildFeatureQuery($aTemplate, $options);
$caMainQuery = str_replace('GROUP BY ', 'WHERE ST_Intersects(ca_geom, gc_geom) GROUP BY ca_geom,', $caMainQuery);
$caMainQuery = str_replace("as gc_geom", "as gc_geom, $addFields", $caMainQuery);
$caMainQuery = str_replace("SELECT *", "SELECT ST_AsText(gc_geom) as gc_geom_wkt,ST_AsText(ST_Transform(gc_geom,$requestSRID)) as gc_geom_wkt_mapsrid,ST_AsText(ca_geom) as ca_geom_wkt,ST_AsText(ST_Transform(ca_geom,$requestSRID)) as ca_geom_wkt_mapsrid,ST_Area(gc_geom) as istat_gc_area,ST_Area(ca_geom) as istat_ca_area, *", $caMainQuery);

$stmt = $dataDB->prepare($caMainQuery);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$featureBuildingsArr = explode(',', $_REQUEST['featureTypeBuildings']);
$caBuildingsQuery = "SELECT count(*) FROM (";

$stmt = $db->prepare($sql);
for($i=0; $i < count($featureBuildingsArr); $i++) {
    list($layergroupName, $layerName) = explode('.', $featureBuildingsArr[$i]);

    $stmt->execute(array(
        'mapset_name'=>$_REQUEST['mapsetName'],
        'layergroup_name'=>$layergroupName,
        'layer_name'=>$layerName
    ));

    $request['layer_id'] = $stmt->fetchColumn(0);

    $oQuery = new PgQuery($request);

    $aTemplate = $oQuery->templates[$request['layer_id']];
    $dataDB = GCApp::getDataDB($aTemplate['catalog_path']);
    $datalayerSchema = GCApp::getDataDBSchema($aTemplate['catalog_path']);
    $aTemplate['table_schema'] = $datalayerSchema;
    $aTemplate['fields'] = $aTemplate['field'];
    $options = array('include_1n_relations'=>true);
    if ($i > 0) {
        $caBuildingsQuery .= ' UNION ';
    }

    $caBuildingsQuery .= str_replace("SELECT *", "SELECT gc_geom", GCAuthor::buildFeatureQuery($aTemplate, $options));
}

$caBuildingsQuery .= ') AS QUERY_B WHERE ST_Intersects(gc_geom, :istat_geom)';
$buildingsSRID = $aTemplate['data_srid'];

$istatOutputData = array();
for($i=0; $i < count($results); $i++) {
    $outRow = $results[$i];
    $gcGeom = $outRow['gc_geom_wkt'];
    $caGeom = $outRow['ca_geom_wkt'];
    if ($dataSRID != $buildingsSRID) {
        $gcGeom = "ST_Transform(ST_GeomFromText('$gcGeom',$dataSRID),$buildingsSRID)";
        $caGeom = "ST_Transform(ST_GeomFromText('$caGeom',$dataSRID),$buildingsSRID)";
    }
    else {
        $gcGeom = "ST_GeomFromText('$gcGeom',$dataSRID)";
        $caGeom = "ST_GeomFromText('$caGeom',$dataSRID)";
    }
    $stmt = $dataDB->prepare(str_replace(':istat_geom', $gcGeom, $caBuildingsQuery));
    $stmt->execute();
    $outRow['gc_n_building'] = $stmt->fetchColumn(0);
    $stmt = $dataDB->prepare(str_replace(':istat_geom', $caGeom, $caBuildingsQuery));
    $stmt->execute();
    $outRow['ca_n_building'] = $stmt->fetchColumn(0);
    $istatOutputData[] = $outRow;
}

die(json_encode($istatOutputData));
ini_set('display_errors', 'On');
error_reporting(E_ALL);
?>
