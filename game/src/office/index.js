import Phaser from 'phaser';
import OfficeScene from './OfficeScene.js';

/**
 * Mounts the pixel office inside `container`.
 * @returns {Promise<{setStates(states:object):void, destroy():void}>}
 */
export async function createOffice(container, { assets, me, onRoom, onCharacter }) {
  // Bubbles and room plates use Silkscreen: make sure it is loaded before drawing text.
  try { await document.fonts.load('8px Silkscreen'); } catch { /* falls back to monospace */ }

  return new Promise((resolve) => {
    const game = new Phaser.Game({
      type: Phaser.AUTO,
      parent: container,
      // Rendered at 2× (camera zoom) so pixel text stays sharp; the browser scales the canvas to fit.
      width: 48 * 16 * 2,
      height: 30 * 16 * 2,
      roundPixels: true,
      pixelArt: true,
      backgroundColor: '#0B0B0B',
      banner: false,
      scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_HORIZONTALLY },
      scene: [],
    });
    game.scene.add('office', OfficeScene, true, {
      assets, me, onRoom, onCharacter,
      onReady: (scene) => resolve({
        setStates: (states) => scene.setStates(states),
        // While an HTML dialog is open, clicks must not reach rooms/characters underneath.
        setInputEnabled: (on) => { scene.input.enabled = on; },
        destroy: () => game.destroy(true),
      }),
    });
  });
}
