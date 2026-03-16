// SVG path data for network topology device icons.
// All paths use -14..+14 coordinate space (28x28 icon, centered at origin).
// Usage: translate(<g>) to node center, bind :d to each path string.
// No v-html — these are plain attribute strings bound via :d in Vue templates.

export const DEVICE_ICONS = {
  router: [
    'M0,-10 A10,10 0 1,1 0,10 A10,10 0 1,1 0,-10',
    'M0,-14 L3,-9 M0,-14 L-3,-9',
    'M0,14 L3,9 M0,14 L-3,9',
    'M-14,0 L-9,3 M-14,0 L-9,-3',
    'M14,0 L9,3 M14,0 L9,-3',
  ],
  switch: [
    'M-12,-6 L12,-6 L12,6 L-12,6 Z',
    'M-8,0 L-14,0 M-14,0 L-11,-3 M-14,0 L-11,3',
    'M8,0 L14,0 M14,0 L11,-3 M14,0 L11,3',
  ],
  firewall: [
    'M0,-13 L11,-7 L11,2 Q11,10 0,14 Q-11,10 -11,2 L-11,-7 Z',
    'M-5,-3 L-5,5 M5,-3 L5,5 M-5,1 L5,1',
  ],
  server: [
    'M-10,-12 L10,-12 L10,-4 L-10,-4 Z',
    'M-10,-2 L10,-2 L10,6 L-10,6 Z',
    'M-10,8 L10,8 L10,12 L-10,12 Z',
    'M7,-9 A1.5,1.5 0 1,1 7.01,-9',
    'M7,-0.5 A1.5,1.5 0 1,1 7.01,-0.5',
  ],
  cloud: [
    'M-8,4 Q-14,4 -14,-2 Q-14,-8 -8,-8 Q-6,-13 0,-13 Q6,-13 8,-8 Q14,-8 14,-2 Q14,4 8,4 Z',
  ],
  workstation: [
    'M-10,-12 L10,-12 L10,2 L-10,2 Z',
    'M-3,2 L-3,8 M3,2 L3,8',
    'M-7,8 L7,8',
  ],
  ap: [
    'M0,8 L0,-4',
    'M-6,-6 Q0,-12 6,-6',
    'M-3,-4 Q0,-8 3,-4',
    'M-10,10 L10,10 L8,14 L-8,14 Z',
  ],
  wre: [
    'M-8,-4 L8,-4 L8,8 L-8,8 Z',
    'M-5,-6 Q0,-12 5,-6',
    'M-2,-5 Q0,-9 2,-5',
  ],
}
