const nucleusTailwindConfig = {
  content: [
    "./*.php",
    "./*.html",
    "./handlers/**/*.php",
    "./includes/**/*.php",
    "./tutorial/**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        navy: "#043873",
        accent: "#FFE492",
        cta: "#4F9CF9",
      },
    },
  },
};

if (typeof module !== "undefined") {
  module.exports = nucleusTailwindConfig;
}

if (typeof window !== "undefined") {
  window.tailwind = window.tailwind || {};
  window.tailwind.config = nucleusTailwindConfig;
}
