// leaflet.heat (UMD) s'accroche au « L » global posé par Leaflet : Leaflet doit
// être évalué AVANT lui. Toujours l'importer par ce module (un import direct
// placé avant Leaflet dans une page suffit à mélanger l'ordre dans les
// morceaux partagés du build : « L is not defined » sur la carte).
import L from 'leaflet';
import 'leaflet.heat';

export default L;
