<?php
namespace Georeferencer\Resources;

class Gdal
{
    const GDAL_GCP = '-gcp %1$s %2$s %3$s %4$s';
    const GDAL_TRANSLATE = 'gdal_translate -of %1$s -a_srs EPSG:4326 %2$s "%3$s" "%4$s"';
    const GDAL_WARP = 'gdalwarp -of %1$s -dstalpha "%2$s" "%3$s"'; // -co TFW=YES creates word file
    const GDAL_INFO = 'gdalinfo -json "%1$s"';
    
    protected $image;
    
    protected $coverageStore;
    
    protected $referencePoints = [];
    
    protected $config;
    
    public function __construct($config = [])
    {
        $this->config = $config;
    }
    
    public function setImage($image)
    {
        $this->image = $image;
        return $this;
    }
    
    public function setCoverageStore($coverageStore)
    {
        $this->coverageStore = $coverageStore;
        return $this;
    }
    
    public function setReferencePoints($referencePoints)
    {
        $this->referencePoints = $referencePoints;
        return $this;
    }
    
    /**
     * @acl access public
     */
    public function convert()
    {
        $this->warp()
            ->addWorkspace()
            ->addCoverageStore()
            ->addCoverageFile()
            ->createGeoJson();
        
        return ['store' => $this->coverageStore];
    }
    
    protected function addWorkspace()
    {
        $config = $this->config;
        $url = $config['geoserver']['url'] . '/workspaces/' . $config['geoserver']['workspace'];
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_GET, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_USERPWD, $config['geoserver']['user'] . ':' . $config['geoserver']['pass']);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-type: text/xml", "Accept: text/xml"));
        $response = curl_exec($handle);
        curl_close($handle);
        
        if (strpos($response, 'No such workspace') === false) {
            return $this;
        }
        $config = $this->config;
        $url = $config['geoserver']['url'] . '/workspaces';
        $request = '<workspace><name>' . $config['geoserver']['workspace'] . '</name></workspace>';
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_USERPWD, $config['geoserver']['user'] . ':' . $config['geoserver']['pass']);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-type: application/xml"));
        curl_setopt($handle, CURLOPT_POSTFIELDS, $request);
        $response = curl_exec($handle);
        curl_close($handle);
        return $this;
    }
    
    protected function addCoverageFile()
    {
        $config = $this->config;
        $url = $config['geoserver']['url'] . '/workspaces/' . $config['geoserver']['workspace'] . '/coveragestores/' .
            $this->coverageStore . '/external.' . $config['gdal']['fileExternal'] .
            '?configure=first&coverageName=' . $this->coverageStore ;
        $request = 'file:/assets/images/' . $this->coverageStore . '_geo_warp.' . $config['gdal']['fileExtension'];
        $handle = curl_init($url);

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_USERPWD, $config['geoserver']['user'] . ':' . $config['geoserver']['pass']);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-type: text/plain"));
        curl_setopt($handle, CURLOPT_POSTFIELDS, $request);
        $response = curl_exec($handle);
        curl_close($handle);
        return $this;
    }
    
    protected function warp()
    {
        $points = [];
        $config = $this->config;
        foreach ($this->referencePoints as $reference) {
            $points[] = sprintf(
                self::GDAL_GCP,
                $reference['tileCoordinatesX'],
                $reference['tileCoordinatesY'],
                $reference['geoCoordinatesX'],
                $reference['geoCoordinatesY']
            );
        }
        $points = implode(' ', $points);
        
        $progress = 0;
        $cmd = sprintf(
            self::GDAL_TRANSLATE,
            $config['gdal']['fileFormat'],
            $points,
            $this->image,
            '/assets/images/' . $this->coverageStore . '_geo.' . $config['gdal']['fileExtension']
        );
        $progress = shell_exec($cmd);

        if (is_null($progress) || strpos($progress, 'done') === false) {
            throw new Exception('There was a problem trying to geo reference original image.');
        }
        
        $cmd = sprintf(
            self::GDAL_WARP,
            $config['gdal']['fileFormat'],
            '/assets/images/' . $this->coverageStore . '_geo.' . $config['gdal']['fileExtension'],
            '/assets/images/' . $this->coverageStore . '_geo_warp.' . $config['gdal']['fileExtension']
        );
        $progress = shell_exec($cmd);

        if (is_null($progress) || strpos($progress, 'done') === false) {
            throw new Exception('There was a problem warping geo referenced image.');
        }
        
        return $this;
    }
    
    protected function addCoverageStore()
    {
        $config = $this->config;
        $url = $config['geoserver']['url'] . '/workspaces/' . $config['geoserver']['workspace'] . '/coveragestores';
        $request = '<coverageStore><name>' . $this->coverageStore . '</name><workspace>' . $config['geoserver']['workspace'] . '</workspace><enabled>true</enabled></coverageStore>';
        $handle = curl_init($url);
//        $f = fopen("/assets/images/request.txt", "w");
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_USERPWD, $config['geoserver']['user'] . ':' . $config['geoserver']['pass']);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-type: application/xml"));
        curl_setopt($handle, CURLOPT_POSTFIELDS, $request);
//        curl_setopt($handle, CURLOPT_VERBOSE, 1);
//        curl_setopt($handle, CURLOPT_STDERR, $f);
        $response = curl_exec($handle);
        curl_close($handle);
//        fclose($f);
        return $this;
    }
    
    public function delete()
    {
        if (empty($this->coverageStore)) {
            throw new Exception('Invalid store.');
        }
        $config = $this->config;
        $url = $config['geoserver']['url'] . '/workspaces/' . $config['geoserver']['workspace'] . '/coveragestores/' . $this->coverageStore . '?recurse=true';
        $handle = curl_init($url);

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_USERPWD, $config['geoserver']['user'] . ':' . $config['geoserver']['pass']);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-type: text/plain"));
        $response = curl_exec($handle);
        curl_close($handle);
        return $this;
    }
    
    protected function createGeoJson()
    {
        $config = $this->config;
        $cmd = sprintf(
            self::GDAL_INFO,
            '/assets/images/' . $this->coverageStore . '_geo_warp.' . $config['gdal']['fileExtension']
        );
        $progress = shell_exec($cmd);

        if (is_null($progress) || strpos($progress, 'cornerCoordinates') === false) {
            throw new Exception('There was a problem creating geoJSON.');
        }
        $data = json_decode($progress);
        
        $geoJson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                'type' => 'Feature',
                'properties' => [],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [
                        [
                            $data->cornerCoordinates->upperLeft[0],
                            $data->cornerCoordinates->upperLeft[1]
                        ],
                        [
                            $data->cornerCoordinates->upperRight[0],
                            $data->cornerCoordinates->upperRight[1]
                        ],
                        [
                            $data->cornerCoordinates->lowerRight[0],
                            $data->cornerCoordinates->lowerRight[1]
                        ],
                        [
                            $data->cornerCoordinates->lowerLeft[0],
                            $data->cornerCoordinates->lowerLeft[1]
                        ],
                        [
                            $data->cornerCoordinates->upperLeft[0],
                            $data->cornerCoordinates->upperLeft[1]
                        ]
                    ]
                ]
            ]
        ]);
        
        file_put_contents('/assets/images/' . $this->coverageStore . '_geo_warp.json', $geoJson);
        
        return $this;
    }
}
