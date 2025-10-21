/* Minimal touch gesture helper focused on horizontal page navigation */
(function(global){
  class ReadingGesture{
    constructor(el, opts={}){
      this.el = el;
      this.opts = Object.assign({
        threshold: 50,
        onSwipeLeft: null,
        onSwipeRight: null,
        onTapCenter: null,
        onDrag: null,
      }, opts);
      this.startX = 0; this.startY = 0; this.currentX = 0; this.currentY = 0; this.isSwiping = false; this.dragging = false; this.startTime = 0;
      this.width = 0;
      if (!el) return;
      this.bind();
    }
    bind(){
      this.onStart = this.onStart.bind(this);
      this.onMove = this.onMove.bind(this);
      this.onEnd = this.onEnd.bind(this);
      this.el.addEventListener('touchstart', this.onStart, {passive:true});
      this.el.addEventListener('touchmove', this.onMove, {passive:false});
      this.el.addEventListener('touchend', this.onEnd, {passive:true});
      this.el.addEventListener('touchcancel', this.onEnd, {passive:true});
    }
    onStart(e){
      if (!e.touches || !e.touches.length) return;
      const t = e.touches[0];
      this.width = this.el.clientWidth || window.innerWidth;
      this.startX = this.currentX = t.clientX;
      this.startY = this.currentY = t.clientY;
      this.isSwiping = true; this.dragging = false; this.startTime = performance.now();
      this.opts.onDrag && this.opts.onDrag(0, {phase:'start'});
    }
    onMove(e){
      if (!this.isSwiping) return;
      const t = e.touches[0];
      if (!t) return;
      this.currentX = t.clientX; this.currentY = t.clientY;
      const dx = this.currentX - this.startX; const dy = this.currentY - this.startY;
      if (Math.abs(dx) > Math.abs(dy)){
        // horizontal gesture
        this.dragging = true;
        e.preventDefault(); // lock vertical scroll when swiping horizontally
        const progress = dx / this.width; // -1..1
        const eased = this.elastic(progress);
        this.opts.onDrag && this.opts.onDrag(dx, {phase:'move', progress, eased});
      }
    }
    onEnd(){
      if (!this.isSwiping){ return; }
      const dx = this.currentX - this.startX; const dy = this.currentY - this.startY;
      const absX = Math.abs(dx); const absY = Math.abs(dy);
      const w = this.width || (this.el?.clientWidth || window.innerWidth);
      const isTap = absX < 8 && absY < 8;
      const threshold = this.opts.threshold || Math.max(36, Math.floor(w * 0.08));
      if (this.dragging){
        // release with decision
        if (absX > threshold && absX > absY){
          if (dx < 0) this.opts.onSwipeLeft && this.opts.onSwipeLeft(); else this.opts.onSwipeRight && this.opts.onSwipeRight();
        } else {
          this.opts.onDrag && this.opts.onDrag(0, {phase:'cancel'});
        }
      } else if (isTap){
        const x = this.startX;
        if (x < w/3){ this.opts.onSwipeRight && this.opts.onSwipeRight(); }
        else if (x > w*2/3){ this.opts.onSwipeLeft && this.opts.onSwipeLeft(); }
        else { this.opts.onTapCenter && this.opts.onTapCenter(); }
      }
      this.isSwiping=false; this.dragging=false; this.opts.onDrag && this.opts.onDrag(0, {phase:'end'});
    }
    elastic(progress){
      // small elastic feel near edges, clamp to about 0.35
      const max = 0.35;
      const p = Math.max(-1, Math.min(1, progress));
      const sign = p<0?-1:1; const val = Math.min(max, Math.abs(p) * 0.6 * (1 - Math.min(1, Math.abs(p)) * 0.3));
      return sign * val;
    }
  }
  global.ReadingGesture = ReadingGesture;
})(window);
