<?php
// Included only after cable.php has authorized and loaded the cable record.
require_once __DIR__.'/measurements.php';
$measurement = pwg_measurement_read($cable['serial_number']);
if (!$measurement) return;
$measurementSelector = !empty($cable['public_token']) ? ['token'=>$cable['public_token']] : ['id'=>$cable['serial_number']];
?>
<section class="cable-card measurement-panel" aria-labelledby="measurement-title">
  <h2 id="measurement-title">Test Measurements</h2>
  <?php if(!empty($measurement['measured_at'])):?><p>Measured <?=h($measurement['measured_at'])?> · instrument local time</p><?php endif;?>
  <p><?=nl2br(h($measurement['metadata']['conditions'] ?? ''))?></p>
  <?php if(isset($measurement['plots']['tdr'])):?>
  <div class="measurement-chart" data-kind="tdr"><h3>TDR impedance</h3><p><span class="legend-s11">● Measured TDR</span> &nbsp; <span class="legend-s22">● Slope-compensated TDR</span></p><p>Drag across the plot to zoom in time. Hover or touch for values.</p>
    <button type="button" data-action="reset">Reset view</button> <button type="button" data-action="fit">Fit impedance range</button>
    <svg viewBox="0 0 900 340" role="img" aria-label="Measured and slope-compensated TDR impedance in ohms versus time in nanoseconds"></svg><p class="measurement-readout" aria-live="polite"></p>
    <p class="measurement-note" data-tdr-fit></p>
    <p class="measurement-note">Slope compensation removes a linear fit over the central 80% of the recorded time range, excluding 10% at each end. It assumes a uniform cable and also removes any genuine linear impedance taper.</p>
    <p class="measurement-note">50 Ω reference shown dashed. Constant open/load calibration, averaged over 0.6–1.8 ns. Values beyond that calibration interval assume stable reference levels. Resistive loss can contribute to the impedance slope.</p>
  </div><?php endif;?>
  <?php if(isset($measurement['plots']['s11']) || isset($measurement['plots']['s22'])):?>
  <div class="measurement-chart" data-kind="reflection"><h3>S11 / S22 reflection</h3><p><span class="legend-s11">● S11</span> &nbsp; <span class="legend-s22">● S22</span> · More-negative values indicate less reflection.</p>
    <button type="button" data-action="reset">Reset view</button><p>Drag across the plot to zoom in frequency. Dots indicate measured frequencies.</p>
    <svg viewBox="0 0 900 340" role="img" aria-label="S11 and S22 reflection magnitude in decibels versus frequency in gigahertz"></svg><p class="measurement-readout" aria-live="polite"></p>
  </div><?php endif;?>
  <div class="cable-actions"><?php foreach(['tdr.txt'=>'TDR','s11.s1p'=>'S11','s22.s1p'=>'S22'] as $file=>$label): if(empty($measurement['raw'][$file])) continue;?>
    <a class="btn btn-secondary" href="/cable-measurements.php?<?=h(http_build_query($measurementSelector + ['file'=>$file]))?>">Download <?=h($label)?></a>
  <?php endforeach;?></div>
  <script type="application/json" id="measurement-data"><?=json_encode($measurement['plots'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)?></script>
</section>
<link rel="stylesheet" href="/assets/measurements.css"><script src="/assets/measurements.js?v=<?=h((string)filemtime(dirname(__DIR__).'/assets/measurements.js'))?>" defer></script>
