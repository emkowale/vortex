<?php
if ( ! class_exists( 'Vortex_GitHub_Updater' ) ) :

class Vortex_GitHub_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $github_api;

    public function __construct( $file ) {
        $this->file     = $file;
        $this->plugin   = get_plugin_data( $file );
        $this->basename = plugin_basename( $file );
        $this->active   = is_plugin_active( $this->basename );

        // Replace with YOUR repo
        $this->github_api = 'https://api.github.com/repos/emkowale/vortex/releases/latest';

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
    }

    private function get_latest_release() {
        $response = wp_remote_get( $this->github_api, [
            'headers' => [ 'User-Agent' => 'WordPress; ' . home_url() ]
        ] );

        if ( is_wp_error( $response ) ) return false;

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $release->tag_name ) || empty( $release->assets ) ) return false;

        $zip = $release->assets[0]->browser_download_url;

        return [
            'version' => ltrim( $release->tag_name, 'v' ),
            'zip'     => $zip,
            'changelog' => $release->body ?? '',
        ];
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release ) return $transient;

        $current_version = $this->plugin['Version'];
        if ( version_compare( $current_version, $release['version'], '>=' ) ) return $transient;

        $obj              = new stdClass();
        $obj->slug        = dirname( $this->basename );
        $obj->plugin      = $this->basename;
        $obj->new_version = $release['version'];
        $obj->package     = $release['zip'];
        $obj->url         = $this->plugin['PluginURI'] ?? '';

        $transient->response[ $this->basename ] = $obj;
        return $transient;
    }

    public function plugin_info( $res, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $res;
        if ( $args->slug !== dirname( $this->basename ) ) return $res;

        $release = $this->get_latest_release();
        if ( ! $release ) return $res;

        return (object)[
            'name'        => $this->plugin['Name'],
            'slug'        => dirname( $this->basename ),
            'version'     => $release['version'],
            'author'      => $this->plugin['Author'],
            'homepage'    => $this->plugin['PluginURI'] ?? '',
            'sections'    => [
                'description' => $this->plugin['Description'],
                'changelog'   => nl2br( $release['changelog'] ),
            ],
            'download_link' => $release['zip'],
        ];
    }
}

endif;
