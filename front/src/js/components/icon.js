// Lógica do componente Icon.vue (o template fica no .vue).

export function useIcon() {
  const PATHS = {
    home: 'M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z',
    sliders: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M2 14h4M10 8h4M18 16h4',
    users: 'M16 19v-1a3 3 0 0 0-3-3H7a3 3 0 0 0-3 3v1M10 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6M20 19v-1a3 3 0 0 0-2.2-2.9M16 6.1a3 3 0 0 1 0 5.8',
    team: 'M8 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6M16 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6M4 19c.5-2 2.2-3 4-3s3.5 1 4 3M12 19c.5-2 2.2-3 4-3s3.5 1 4 3',
    building: 'M4 20V6l8-3 8 3v14M9 20v-5h6v5M9 9h.01M12 9h.01M15 9h.01M9 12h.01M12 12h.01M15 12h.01',
    grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    menu: 'M4 7h16M4 12h16M4 17h16',
    alert: 'M12 4 3 20h18zM12 10v4M12 17h.01',
    file: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    ticket: 'M4 7h16v3a2 2 0 0 0 0 4v3H4v-3a2 2 0 0 0 0-4zM14 7v10',
    calendar: 'M5 6h14v14H5zM8 4v4M16 4v4M5 10h14',
    chart: 'M4 19V5M4 19h16M8 16v-4M12 16V8M16 16v-6',
    bolt: 'M13 3 5 14h7l-1 7 8-11h-7z',
    star: 'm12 3 2.4 5.4L20 9.2l-4 4.1.9 5.7L12 16.4 7.1 19l.9-5.7-4-4.1 5.6-.8z',
    trophy: 'M8 4h8v4a4 4 0 0 1-8 0zM6 5H4a3 3 0 0 0 3 4M18 5h2a3 3 0 0 1-3 4M9 20h6M12 14v6',
    bell: 'M6 16V10a6 6 0 1 1 12 0v6l1.5 2h-15zM10 19a2 2 0 0 0 4 0',
    access: 'M12 3a4 4 0 0 1 4 4v1h1a2 2 0 0 1 2 2v8H5v-8a2 2 0 0 1 2-2h1V7a4 4 0 0 1 4-4z',
    mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
    lock: 'M8 10V8a4 4 0 0 1 8 0v2M6 10h12v10H6z',
    search: 'M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14zM20 20l-3.5-3.5',
    info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8h.01',
    check: 'M5 12.5 9.2 17 19 7',
    logout: 'M10 7V5H5v14h5v-2M10 12h9M16 9l3 3-3 3',
    eye: 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    x: 'M6 6l12 12M18 6 6 18',
    chevron: 'M9 6l6 6-6 6',
    refresh: 'M21 4v6h-6M3 20v-6h6M5.5 9a7.5 7.5 0 0 1 12.4-2.8L21 10M3 14l3.1 3.8A7.5 7.5 0 0 0 18.5 15',
  }

  return {
    PATHS,
  }
}
