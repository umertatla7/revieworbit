"use client";

import { useEffect, useRef, useState } from "react";

export type PerspectiveTextConfig = {
  color?: string;
  color_mode?: "solid" | "gradient";
  gradient_start?: string;
  gradient_end?: string;
  font_size?: number;
  min_font_size?: number;
  font_family?: string;
  align?: "left" | "center" | "right";
  max_lines?: number;
  top_left_x?: number; top_left_y?: number;
  top_right_x?: number; top_right_y?: number;
  bottom_right_x?: number; bottom_right_y?: number;
  bottom_left_x?: number; bottom_left_y?: number;
};

type Point = { x: number; y: number };

function distance(a: Point, b: Point): number {
  return Math.hypot(b.x - a.x, b.y - a.y);
}

function matrix3d(sourceWidth: number, sourceHeight: number, [p0, p1, p2, p3]: Point[]): string {
  const dx1 = p1.x - p2.x;
  const dx2 = p3.x - p2.x;
  const dx3 = p0.x - p1.x + p2.x - p3.x;
  const dy1 = p1.y - p2.y;
  const dy2 = p3.y - p2.y;
  const dy3 = p0.y - p1.y + p2.y - p3.y;
  const determinant = dx1 * dy2 - dx2 * dy1;
  const g = Math.abs(determinant) < 0.000001 ? 0 : (dx3 * dy2 - dx2 * dy3) / determinant;
  const h = Math.abs(determinant) < 0.000001 ? 0 : (dx1 * dy3 - dx3 * dy1) / determinant;
  const a = (p1.x - p0.x + g * p1.x) / sourceWidth;
  const b = (p3.x - p0.x + h * p3.x) / sourceHeight;
  const d = (p1.y - p0.y + g * p1.y) / sourceWidth;
  const e = (p3.y - p0.y + h * p3.y) / sourceHeight;
  const gx = g / sourceWidth;
  const hy = h / sourceHeight;

  return `matrix3d(${a},${d},0,${gx},${b},${e},0,${hy},0,0,1,0,${p0.x},${p0.y},0,1)`;
}

function wrapAndFit(text: string, family: string, maxSize: number, minSize: number, width: number, height: number, maxLines: number) {
  if (typeof document === "undefined") return { lines: [text], size: minSize };
  const context = document.createElement("canvas").getContext("2d");
  if (!context) return { lines: [text], size: minSize };

  for (let size = maxSize; size >= minSize; size -= 2) {
    context.font = `${size}px "${family}"`;
    const words = text.trim().split(/\s+/).filter(Boolean);
    const lines: string[] = [];
    let current = "";
    for (const word of words.length ? words : [text]) {
      if (context.measureText(word).width > width) {
        if (current) { lines.push(current); current = ""; }
        let chunk = "";
        for (const character of [...word]) {
          if (chunk && context.measureText(chunk + character).width > width) {
            lines.push(chunk);
            chunk = character;
          } else {
            chunk += character;
          }
        }
        current = chunk;
        continue;
      }
      const candidate = current ? `${current} ${word}` : word;
      if (current && context.measureText(candidate).width > width) {
        lines.push(current);
        current = word;
      } else {
        current = candidate;
      }
    }
    if (current) lines.push(current);
    if (lines.length <= maxLines && Math.max(...lines.map((line) => context.measureText(line).width), 0) <= width && lines.length * size * 1.08 <= height) {
      return { lines, size };
    }
  }

  return { lines: [text], size: minSize };
}

export function PerspectiveTextPreview({ config, text, imageWidth, imageHeight }: { config: PerspectiveTextConfig; text: string; imageWidth: number; imageHeight: number }) {
  const ref = useRef<HTMLDivElement>(null);
  const [size, setSize] = useState({ width: 1, height: 1 });
  const [fontRevision, refreshFonts] = useState(0);

  useEffect(() => {
    if (!ref.current) return;
    const observer = new ResizeObserver(([entry]) => setSize({ width: entry.contentRect.width, height: entry.contentRect.height }));
    observer.observe(ref.current);
    return () => observer.disconnect();
  }, []);
  useEffect(() => {
    void document.fonts.load(`32px "${config.font_family ?? "Poppins"}"`).then(() => refreshFonts((value) => value + 1));
  }, [config.font_family]);

  const render = (() => {
    void fontRevision;
    const percentages: Point[] = [
      { x: config.top_left_x ?? 22, y: config.top_left_y ?? 38 },
      { x: config.top_right_x ?? 78, y: config.top_right_y ?? 38 },
      { x: config.bottom_right_x ?? 78, y: config.bottom_right_y ?? 62 },
      { x: config.bottom_left_x ?? 22, y: config.bottom_left_y ?? 62 },
    ];
    const destination = percentages.map((point) => ({ x: point.x / 100 * size.width, y: point.y / 100 * size.height }));
    const original = percentages.map((point) => ({ x: point.x / 100 * imageWidth, y: point.y / 100 * imageHeight }));
    const sourceWidth = Math.max(40, (distance(destination[0], destination[1]) + distance(destination[3], destination[2])) / 2);
    const sourceHeight = Math.max(24, (distance(destination[0], destination[3]) + distance(destination[1], destination[2])) / 2);
    const originalWidth = Math.max(40, (distance(original[0], original[1]) + distance(original[3], original[2])) / 2);
    const originalHeight = Math.max(24, (distance(original[0], original[3]) + distance(original[1], original[2])) / 2);
    const fitted = wrapAndFit(text, config.font_family ?? "Poppins", config.font_size ?? 72, config.min_font_size ?? 16, originalWidth, originalHeight, config.max_lines ?? 2);
    return {
      sourceWidth,
      sourceHeight,
      transform: matrix3d(sourceWidth, sourceHeight, destination),
      lines: fitted.lines,
      fontSize: fitted.size * (sourceHeight / originalHeight),
    };
  })();

  return <div ref={ref} className="pointer-events-none absolute inset-0 overflow-hidden">
    <div className="absolute left-0 top-0 flex px-1" style={{
      width: render.sourceWidth,
      height: render.sourceHeight,
      transform: render.transform,
      transformOrigin: "0 0",
      alignItems: "center",
      justifyContent: config.align === "left" ? "flex-start" : config.align === "right" ? "flex-end" : "center",
      color: config.color_mode === "gradient" ? "transparent" : (config.color ?? "#17201b"),
      backgroundImage: config.color_mode === "gradient" ? `linear-gradient(135deg, ${config.gradient_start ?? "#174d3b"}, ${config.gradient_end ?? "#7c3aed"})` : undefined,
      backgroundClip: config.color_mode === "gradient" ? "text" : undefined,
      WebkitBackgroundClip: config.color_mode === "gradient" ? "text" : undefined,
      fontFamily: `"${config.font_family ?? "Poppins"}"`,
      fontSize: render.fontSize,
      lineHeight: 1.08,
      textAlign: config.align ?? "center",
    }}><span className="block w-full">{render.lines.map((line, index) => <span className="block" key={`${line}-${index}`}>{line}</span>)}</span></div>
  </div>;
}
