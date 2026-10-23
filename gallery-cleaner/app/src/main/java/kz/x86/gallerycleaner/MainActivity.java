package kz.x86.gallerycleaner;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ContentUris;
import android.content.Intent;
import android.content.IntentSender;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.MediaStore;
import android.provider.Settings;
import android.util.LruCache;
import android.util.Size;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.BaseAdapter;
import android.widget.Button;
import android.widget.GridView;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import com.google.android.gms.tasks.Tasks;
import com.google.mlkit.vision.common.InputImage;
import com.google.mlkit.vision.label.ImageLabel;
import com.google.mlkit.vision.label.ImageLabeler;
import com.google.mlkit.vision.label.ImageLabeling;
import com.google.mlkit.vision.label.defaults.ImageLabelerOptions;

import java.io.InputStream;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

public class MainActivity extends Activity {
    static final int ASK = 12, TRASH = 13, MANAGE = 14, BATCH = 800;
    static final int BLUE = Color.rgb(35,105,225), RED = Color.rgb(193,58,75);
    static final String[] FILTERS = {"Все", "Дубли", "Похожие", "Скриншоты", "WhatsApp", "Техника (ИИ)"};
    final ExecutorService worker = Executors.newSingleThreadExecutor();
    final ExecutorService thumbs = Executors.newFixedThreadPool(3);
    final Handler ui = new Handler(Looper.getMainLooper());
    final AtomicBoolean busy = new AtomicBoolean(false);
    final ArrayList<Photo> photos = new ArrayList<>(), visible = new ArrayList<>();
    final HashSet<Long> selected = new HashSet<>();
    final ArrayList<Long> pendingTrash = new ArrayList<>();
    final LruCache<Long, Bitmap> cache = new LruCache<Long, Bitmap>(24*1024) {
        @Override protected int sizeOf(Long key, Bitmap value) { return Math.max(1, value.getByteCount()/1024); }
    };
    TextView status, counts;
    Button selectAllButton, mediaPermissionButton;
    boolean trashInProgress = false;
    int trashTotal = 0, trashed = 0;
    LinearLayout tabs;
    GridView grid;
    GalleryAdapter adapter;
    String filter = "Все";
    volatile boolean cancelled;

    static final class Photo {
        long id, size, date;
        int width, height;
        String name, path;
        Uri uri;
        boolean exact, similar, anchor, screenshot, whatsapp, tech;
        long hash;
        int red, green, blue;
        double variance;
    }

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        render();
        status.setText("Снимки остаются на телефоне. Без интернета, без подписки, без автoудаления.");
        if (!fullAccess()) new AlertDialog.Builder(this)
          .setTitle("Доступ к фотографиям")
          .setMessage("Для поиска дублей необходим доступ ко всем фотографиям.")
          .setPositiveButton("Разрешить", (d,w) -> askAccess())
          .setNegativeButton("Позже", null).show();
    }

    int dp(float px) { return (int)(px*getResources().getDisplayMetrics().density+.5f); }
    GradientDrawable bg(int color) {
        GradientDrawable d = new GradientDrawable();
        d.setColor(color); d.setCornerRadius(dp(10)); return d;
    }
    Button button(String label, int color) {
        Button b = new Button(this);
        b.setAllCaps(false); b.setText(label); b.setTextColor(Color.WHITE);
        b.setTextSize(12); b.setPadding(dp(2), 0, dp(2), 0); b.setBackground(bg(color)); return b;
    }
    void render() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(12),dp(14),dp(12),dp(8));
        root.setBackgroundColor(Color.rgb(247,249,252));
        setContentView(root);
        TextView title = new TextView(this);
        title.setText("X86 Галерея 0.3"); title.setTextSize(24);
        title.setTextColor(Color.rgb(29,43,63)); title.setTypeface(null,1);
        root.addView(title);
        status = new TextView(this);
        status.setTextSize(13); status.setTextColor(Color.rgb(47,58,75));
        status.setMinHeight(dp(56)); status.setPadding(0,dp(9),0,dp(5));
        root.addView(status);
        LinearLayout top = new LinearLayout(this); root.addView(top);
        Button scan = button("Сканировать",BLUE);
        top.addView(scan,new LinearLayout.LayoutParams(0,dp(48),1));
        scan.setOnClickListener(v -> scan());
        Button selectDup = button("Выбрать дубли",Color.rgb(55,130,81));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(0,dp(48),1.15f);
        params.leftMargin=dp(6); top.addView(selectDup,params);
        selectDup.setOnClickListener(v -> {
            if(busy.get()) {toast("Подожди завершения сканирования");return;}
            selected.clear(); for(Photo p:photos) if(p.exact) selected.add(p.id);
            filter="Дубли"; renderTabs(); applyFilter();
            toast("Выбраны побайтовые дубли. Проверь перед удалением!");
        });
        Button ml = button("ИИ: найти электронику на фото",Color.rgb(103,80,177));
        LinearLayout.LayoutParams full = new LinearLayout.LayoutParams(-1,dp(43));
        full.topMargin=dp(6);root.addView(ml,full);
        ml.setOnClickListener(v -> runML());
        mediaPermissionButton = button("Разрешить удаление без запросов Android", Color.rgb(63,116,139));
        LinearLayout.LayoutParams mediaParams = new LinearLayout.LayoutParams(-1,dp(42));
        mediaParams.topMargin = dp(6);
        root.addView(mediaPermissionButton,mediaParams);
        mediaPermissionButton.setOnClickListener(v -> openMediaPermissionSettings());
        refreshMediaPermissionButton();
        LinearLayout bottom = new LinearLayout(this); root.addView(bottom);
        Button clear = button("Снять выделение",Color.rgb(109,122,138));
        bottom.addView(clear,new LinearLayout.LayoutParams(0,dp(44),1));
        clear.setOnClickListener(v -> {selected.clear();applyFilter();});
        Button trash = button("В корзину",RED);
        LinearLayout.LayoutParams btp = new LinearLayout.LayoutParams(0,dp(44),1);
        btp.leftMargin=dp(6); bottom.addView(trash,btp);
        trash.setOnClickListener(v -> confirmTrash());
        counts = new TextView(this); counts.setTextColor(Color.DKGRAY);
        counts.setPadding(0,dp(9),0,dp(6));root.addView(counts);
        HorizontalScrollView scroller = new HorizontalScrollView(this);
        scroller.setHorizontalScrollBarEnabled(false);root.addView(scroller);
        tabs = new LinearLayout(this);scroller.addView(tabs);renderTabs();
        selectAllButton = button("Выбрать все в разделе", BLUE);
        LinearLayout.LayoutParams selectLayout = new LinearLayout.LayoutParams(-1,dp(45));
        selectLayout.topMargin=dp(6);
        root.addView(selectAllButton,selectLayout);
        selectAllButton.setOnClickListener(v -> toggleSelectAllVisible());
        grid = new GridView(this);grid.setNumColumns(3);
        grid.setHorizontalSpacing(dp(6));grid.setVerticalSpacing(dp(6));
        grid.setPadding(0,dp(7),0,0);grid.setClipToPadding(false);
        adapter = new GalleryAdapter();grid.setAdapter(adapter);
        root.addView(grid,new LinearLayout.LayoutParams(-1,0,1));
        TextView foot = new TextView(this);
        foot.setText("ИИ отмечает только возможные фото электроники. Снимки плат могут быть полезными — не удаляй их вслепую.");
        foot.setTextColor(Color.DKGRAY);foot.setTextSize(11);root.addView(foot);
        updateCount();
    }
    void renderTabs() {
        tabs.removeAllViews();
        for(String name:FILTERS) {
            Button b=button(name,filter.equals(name)?BLUE:Color.rgb(123,137,154));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-2,dp(39));
            lp.rightMargin=dp(6);tabs.addView(b,lp);
            b.setOnClickListener(v -> {filter=name;renderTabs();applyFilter();});
        }
    }
    boolean fullAccess() {
        return checkSelfPermission(Build.VERSION.SDK_INT>=33 ? Manifest.permission.READ_MEDIA_IMAGES
                 : Manifest.permission.READ_EXTERNAL_STORAGE)==PackageManager.PERMISSION_GRANTED;
    }
    void askAccess(){
        if(Build.VERSION.SDK_INT>=34)
            requestPermissions(new String[]{Manifest.permission.READ_MEDIA_IMAGES,
                Manifest.permission.READ_MEDIA_VISUAL_USER_SELECTED},ASK);
        else if(Build.VERSION.SDK_INT>=33)
            requestPermissions(new String[]{Manifest.permission.READ_MEDIA_IMAGES},ASK);
        else requestPermissions(new String[]{Manifest.permission.READ_EXTERNAL_STORAGE},ASK);
    }
    @Override public void onRequestPermissionsResult(int code,String[] permissions,int[] result){
        super.onRequestPermissionsResult(code,permissions,result);
        if(code!=ASK)return;
        if(fullAccess())scan();
        else new AlertDialog.Builder(this)
            .setTitle("Нужен полный доступ")
            .setMessage("Выбери разрешение ко всем фотографиям в настройках приложения.")
            .setPositiveButton("Настройки",(d,w)->startActivity(new Intent(
                Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+getPackageName()))))
            .setNegativeButton("Позже",null).show();
    }
    void toast(String msg){ Toast.makeText(this,msg,Toast.LENGTH_LONG).show(); }
    void note(String msg){ ui.post(() -> status.setText(msg)); }
    void scan() {
        if(busy.get()) {toast("Уже сканирую");return;}
        if(!fullAccess()){askAccess();return;}
        busy.set(true);cancelled=false;
        getWindow().addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        note("Получаю список изображений...");
        worker.execute(() -> {
            try {
                ArrayList<Photo> list=query();
                note("Изображений: "+list.size()+". Проверяю точные совпадения...");
                exactDuplicates(list);
                note("Ищу визуально похожие снимки...");
                similarDuplicates(list);
                ui.post(() -> {
                    photos.clear();photos.addAll(list);selected.clear();
                    busy.set(false);getWindow().clearFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
                    applyFilter();
                    int a=0,b=0,c=0,d=0;
                    for(Photo p:photos){if(p.exact)a++;if(p.similar)b++;if(p.screenshot)c++;if(p.whatsapp)d++;}
                    status.setText("Найдено "+list.size()+" • дубли "+a+" • похожие "+b+
                        " • скриншоты "+c+" • WhatsApp "+d+". Ничего не удалено.");
                });
            } catch(Exception e){
                ui.post(()->{
                    busy.set(false);getWindow().clearFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
                    status.setText("Ошибка сканирования: "+e.getClass().getSimpleName());
                });
            }
        });
    }
    ArrayList<Photo> query() {
        ArrayList<Photo> list=new ArrayList<>();
        String[] cols={MediaStore.Images.Media._ID,MediaStore.Images.Media.DISPLAY_NAME,
            MediaStore.Images.Media.SIZE,MediaStore.Images.Media.DATE_TAKEN,
            MediaStore.Images.Media.RELATIVE_PATH,MediaStore.Images.Media.WIDTH,
            MediaStore.Images.Media.HEIGHT,MediaStore.Images.Media.MIME_TYPE};
        try(Cursor c=getContentResolver().query(MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
          cols,MediaStore.Images.Media.SIZE+" > 0",null,MediaStore.Images.Media.DATE_ADDED+" DESC")){
            if(c==null)return list;
            while(c.moveToNext()&&!cancelled){
                String type=c.getString(7);
                if(type==null||!type.startsWith("image/"))continue;
                Photo p=new Photo();
                p.id=c.getLong(0);p.uri=ContentUris.withAppendedId(MediaStore.Images.Media.EXTERNAL_CONTENT_URI,p.id);
                p.name=c.getString(1)==null?"Без названия":c.getString(1);
                p.size=c.getLong(2);p.date=c.getLong(3);
                p.path=c.getString(4)==null?"":c.getString(4);
                p.width=c.getInt(5);p.height=c.getInt(6);
                String label=(p.path+"/"+p.name).toLowerCase(Locale.ROOT);
                p.screenshot=label.contains("screenshot")||label.contains("screen_capture")||label.contains("скриншот");
                p.whatsapp=label.contains("whatsapp")||label.contains("wa images")||label.contains("whats app");
                p.tech=label.contains("pcb")||label.contains("board")||label.contains("плат")||
                       label.contains("ремонт")||label.contains("microscope")||label.contains("микросхем");
                list.add(p);
            }
        }
        list.sort((a,b)->Long.compare(b.date>0?b.date:b.id,a.date>0?a.date:a.id));
        return list;
    }
    String digest(Uri uri)throws Exception {
        MessageDigest sha=MessageDigest.getInstance("SHA-256");
        byte[] buffer=new byte[65536];
        try(InputStream in=getContentResolver().openInputStream(uri)){
            if(in==null)throw new Exception("unreadable");
            int count;
            while((count=in.read(buffer))!=-1)if(count>0)sha.update(buffer,0,count);
        }
        byte[] raw=sha.digest();
        StringBuilder out=new StringBuilder();
        for(byte b:raw)out.append(String.format(Locale.ROOT,"%02x",b&255));
        return out.toString();
    }
    void exactDuplicates(List<Photo> list){
        HashMap<Long,ArrayList<Photo>> bySize=new HashMap<>();
        for(Photo p:list)bySize.computeIfAbsent(p.size,k->new ArrayList<>()).add(p);
        HashSet<String> seen=new HashSet<>();
        int done=0;
        for(ArrayList<Photo> group:bySize.values()){
            if(group.size()<2)continue;
            for(Photo p:group){
                if(cancelled)return;
                try{String key=p.size+":"+digest(p.uri);if(!seen.add(key))p.exact=true;}
                catch(Exception ignored){}
                done++;if(done%70==0)note("Сверяю совпадения: "+done+" файлов...");
            }
        }
    }
    void similarDuplicates(List<Photo> list){
        Map<Integer,ArrayList<Photo>> bins=new HashMap<>();
        int done=0;
        for(Photo p:list){
            if(cancelled)return;
            if(++done%100==0)note("Сравниваю похожие: "+done+" / "+list.size());
            if(p.width<250||p.height<250||p.exact)continue;
            try{
                Bitmap raw=getContentResolver().loadThumbnail(p.uri,new Size(96,96),null);
                if(raw==null)continue;
                Bitmap small=Bitmap.createScaledBitmap(raw,9,8,true);
                long bits=0;int[][] lum=new int[8][9];int sr=0,sg=0,sb=0;double sum=0,sq=0;
                for(int y=0;y<8;y++)for(int x=0;x<9;x++){
                    int pixel=small.getPixel(x,y),r=Color.red(pixel),g=Color.green(pixel),b=Color.blue(pixel);
                    sr+=r;sg+=g;sb+=b;int l=(299*r+587*g+114*b)/1000;
                    lum[y][x]=l;sum+=l;sq+=(double)l*l;
                }
                for(int y=0;y<8;y++)for(int x=0;x<8;x++){
                    bits<<=1;if(lum[y][x]>lum[y][x+1])bits|=1L;
                }
                if(small!=raw)small.recycle();raw.recycle();
                p.hash=bits;p.red=sr/72;p.green=sg/72;p.blue=sb/72;
                p.variance=Math.sqrt(Math.max(0,sq/72-(sum/72)*(sum/72)));
                if(p.variance<12)continue;
                HashSet<Photo> candidate=new HashSet<>();
                for(int part=0;part<8;part++){
                    int bucket=(part<<8)+(int)((bits>>>(part*8))&255);
                    ArrayList<Photo> group=bins.get(bucket);
                    if(group!=null&&group.size()<450)candidate.addAll(group);
                }
                for(Photo q:candidate){
                    if(q.exact)continue;
                    double aspectP=(double)p.width/p.height,aspectQ=(double)q.width/q.height;
                    if(Math.abs(aspectP-aspectQ)>0.012*Math.max(aspectP,aspectQ))continue;
                    if(Long.bitCount(bits^q.hash)>3)continue;
                    double delta=(Math.abs(p.red-q.red)+Math.abs(p.green-q.green)+Math.abs(p.blue-q.blue))/3.0;
                    if(delta>11)continue;
                    p.similar=true;q.anchor=true;break;
                }
                for(int part=0;part<8;part++){
                    int bucket=(part<<8)+(int)((bits>>>(part*8))&255);
                    bins.computeIfAbsent(bucket,k->new ArrayList<>()).add(p);
                }
            }catch(Exception ignored){}
        }
    }
    void runML(){
        if(busy.get()){toast("Идёт анализ");return;}
        if(photos.isEmpty()){toast("Сначала нажми «Сканировать»");return;}
        if(!fullAccess()){askAccess();return;}
        busy.set(true);cancelled=false;getWindow().addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        worker.execute(()->{
            ImageLabeler model=ImageLabeling.getClient(ImageLabelerOptions.DEFAULT_OPTIONS);
            try{
                for(int i=0;i<photos.size()&&!cancelled;i++){
                    Photo p=photos.get(i);
                    if(i%30==0)note("ИИ изучает фото: "+(i+1)+" / "+photos.size());
                    Bitmap bmp=null;
                    try{
                        bmp=getContentResolver().loadThumbnail(p.uri,new Size(224,224),null);
                        if(bmp==null)continue;
                        List<ImageLabel> labels=Tasks.await(model.process(InputImage.fromBitmap(bmp,0)),15,TimeUnit.SECONDS);
                        for(ImageLabel label:labels){
                            String txt=label.getText().toLowerCase(Locale.ROOT);
                            if(txt.contains("electronic")||txt.contains("circuit")||txt.contains("computer")||
                               txt.contains("hardware")||txt.contains("laptop")||txt.contains("technology")||
                               txt.contains("mobile phone")||txt.contains("keyboard")||txt.contains("motherboard")){
                                p.tech=true;break;
                            }
                        }
                    }catch(Exception ignored){}finally{if(bmp!=null)bmp.recycle();}
                }
            }finally{
                model.close();
                ui.post(()->{
                    busy.set(false);getWindow().clearFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
                    filter="Техника (ИИ)";renderTabs();applyFilter();
                    status.setText("ИИ завершил проверку. Возможные технические фото: "+visible.size()+
                        ". Это кандидаты на просмотр, а не список для автоматического удаления.");
                });
            }
        });
    }
    void applyFilter(){
        visible.clear();
        for(Photo p:photos){
            if(filter.equals("Все")||(filter.equals("Дубли")&&p.exact)||
                (filter.equals("Похожие")&&(p.similar||p.anchor))||
                (filter.equals("Скриншоты")&&p.screenshot)||
                (filter.equals("WhatsApp")&&p.whatsapp)||
                (filter.equals("Техника (ИИ)")&&p.tech))visible.add(p);
        }
        updateCount();if(adapter!=null)adapter.notifyDataSetChanged();
        if(grid!=null)grid.setSelection(0);
    }
    void toggleSelectAllVisible(){
        if(busy.get()){toast("Подожди завершения анализа");return;}
        if(visible.isEmpty()){toast("В этом разделе нет фотографий");return;}
        boolean allSelected = true;
        for(Photo p:visible)if(!selected.contains(p.id)){allSelected=false;break;}
        if(allSelected){
            for(Photo p:visible)selected.remove(p.id);
        }else{
            for(Photo p:visible)selected.add(p.id);
        }
        updateCount();
        adapter.notifyDataSetChanged();
    }
    void updateCount(){
        if(counts!=null)counts.setText("Показано: "+visible.size()+"  •  Выбрано: "+selected.size());
        if(selectAllButton!=null){
            boolean allSelected=!visible.isEmpty();
            for(Photo p:visible)if(!selected.contains(p.id)){allSelected=false;break;}
            selectAllButton.setText(allSelected?"Снять выбор в этом разделе ("+visible.size()+")":
                "Выбрать все в разделе ("+visible.size()+")");
            selectAllButton.setEnabled(!visible.isEmpty()&&!busy.get());
            selectAllButton.setAlpha(visible.isEmpty()?0.5f:1f);
        }
    }
    boolean canManageMedia(){
        return Build.VERSION.SDK_INT >= 31 && MediaStore.canManageMedia(this);
    }
    void refreshMediaPermissionButton(){
        if(mediaPermissionButton==null)return;
        if(Build.VERSION.SDK_INT<31){
            mediaPermissionButton.setText("Системные подтверждения обязательны на Android 11");
            mediaPermissionButton.setEnabled(false);
        }else if(canManageMedia()){
            mediaPermissionButton.setText("✓ Управление медиа разрешено — без повторных запросов");
        }else{
            mediaPermissionButton.setText("Разрешить удаление без запросов Android");
        }
    }
    void openMediaPermissionSettings(){
        if(Build.VERSION.SDK_INT<31){
            toast("На этой версии Android нужно подтверждать каждую партию.");
            return;
        }
        if(canManageMedia()){
            toast("Доступ уже разрешён. После выбора фото нажми «В корзину».");
            return;
        }
        new AlertDialog.Builder(this)
            .setTitle("Один раз разрешить управление медиа?")
            .setMessage("В настройках Android включи для X86 Галерея разрешение «Управление медиафайлами». " +
                "Это позволит переносить выбранные фото в корзину без системного окна на каждую партию. " +
                "Приложение всё равно спрашивает твоё согласие перед удалением.")
            .setNegativeButton("Отмена",null)
            .setPositiveButton("Открыть настройки",(d,w)->{
                try{
                    startActivityForResult(new Intent(Settings.ACTION_REQUEST_MANAGE_MEDIA),MANAGE);
                }catch(Exception e){
                    toast("Не удалось открыть настройки управления медиа");
                }
            }).show();
    }
    @Override protected void onResume(){
        super.onResume();
        refreshMediaPermissionButton();
    }
    void confirmTrash(){
        if(busy.get() || trashInProgress){toast("Дождись окончания текущей операции");return;}
        if(selected.isEmpty()){toast("Выбери ненужные фотографии");return;}
        boolean managed = canManageMedia();
        String msg = "Выбрано "+selected.size()+" фото. ";
        if(managed) msg += "Ты подтверждаешь один раз здесь; остальные партии приложение перенесёт в корзину автоматически, без системных окон.";
        else msg += "Без специального разрешения Android может запросить подтверждение на каждую партию до "+BATCH+" фото. Разрешение можно включить выше.";
        msg += " Фотографии будут в корзине, а не удалены навсегда.";
        new AlertDialog.Builder(this).setTitle("Переместить в корзину?")
            .setMessage(msg)
            .setNegativeButton("Отмена",null)
            .setPositiveButton("В корзину",(d,w)->{
                trashInProgress=true;
                trashTotal=selected.size();
                trashed=0;
                requestTrash();
            }).show();
    }
    void requestTrash(){
        pendingTrash.clear();
        ArrayList<Uri> uris=new ArrayList<>();
        for(Photo p:photos)if(selected.contains(p.id)){
            uris.add(p.uri);pendingTrash.add(p.id);if(uris.size()>=BATCH)break;
        }
        if(uris.isEmpty()){
            trashInProgress=false;
            status.setText("Перемещено в корзину: "+trashed+" фото.");
            return;
        }
        status.setText("Перенос в корзину: "+trashed+" из "+trashTotal+"...");
        try{
            IntentSender sender=MediaStore.createTrashRequest(getContentResolver(),uris,true).getIntentSender();
            startIntentSenderForResult(sender,TRASH,null,0,0,0);
        }catch(Exception e){
            pendingTrash.clear();trashInProgress=false;
            status.setText("Не удалось открыть корзину ("+e.getClass().getSimpleName()+"). Оставшиеся фото не тронуты.");
        }
    }
    @Override protected void onActivityResult(int request,int result,Intent data){
        super.onActivityResult(request,result,data);
        if(request==MANAGE){
            refreshMediaPermissionButton();
            if(canManageMedia())toast("Готово! Теперь фото можно отправлять в корзину без повторных системных запросов.");
            return;
        }
        if(request!=TRASH)return;
        if(result==RESULT_OK){
            HashSet<Long> moved=new HashSet<>(pendingTrash);
            trashed+=moved.size();
            selected.removeAll(moved);photos.removeIf(p->moved.contains(p.id));
            pendingTrash.clear();
            applyFilter();
            if(!selected.isEmpty()&&canManageMedia()&&trashInProgress){
                status.setText("Перемещено "+trashed+" из "+trashTotal+". Продолжаю без подтверждений...");
                ui.postDelayed(this::requestTrash,60);
            }else{
                trashInProgress=false;
                status.setText("Перемещено в корзину: "+trashed+". Осталось выбрано: "+selected.size()+
                    (selected.isEmpty()?". Готово.":". Можно продолжить после разрешения."));
            }
        }else{
            trashInProgress=false;
            status.setText("Системное действие отменено. Уже перемещено: "+trashed+
                ", осталось выбрано: "+selected.size()+".");
            pendingTrash.clear();
        }
    }
    @Override public void onBackPressed(){
        if(busy.get())new AlertDialog.Builder(this).setTitle("Остановить анализ?")
            .setPositiveButton("Остановить",(d,w)->{cancelled=true;super.onBackPressed();})
            .setNegativeButton("Продолжить",null).show();
        else super.onBackPressed();
    }
    @Override protected void onDestroy(){cancelled=true;worker.shutdownNow();thumbs.shutdownNow();super.onDestroy();}
    class GalleryAdapter extends BaseAdapter {
        @Override public int getCount(){return visible.size();}
        @Override public Object getItem(int i){return visible.get(i);}
        @Override public long getItemId(int i){return visible.get(i).id;}
        @Override public View getView(int position,View convert,ViewGroup parent){
            Photo p=visible.get(position);
            Holder h;
            if(convert==null){
                LinearLayout box=new LinearLayout(MainActivity.this);box.setOrientation(LinearLayout.VERTICAL);
                box.setPadding(dp(3),dp(3),dp(3),dp(5));
                ImageView image=new ImageView(MainActivity.this);
                image.setScaleType(ImageView.ScaleType.CENTER_CROP);
                image.setBackgroundColor(Color.rgb(227,231,238));
                box.addView(image,new LinearLayout.LayoutParams(-1,dp(110)));
                TextView marker=new TextView(MainActivity.this);marker.setTextSize(10);marker.setSingleLine(true);
                box.addView(marker);
                TextView label=new TextView(MainActivity.this);label.setTextSize(10);
                label.setSingleLine(true);label.setEllipsize(android.text.TextUtils.TruncateAt.END);
                box.addView(label);
                h=new Holder(image,marker,label);box.setTag(h);convert=box;
            }else h=(Holder)convert.getTag();
            h.marker.setText(selected.contains(p.id)?"✓ Выбрано":p.exact?"● Точный дубль":
                p.similar?"● Похожее":p.anchor?"○ Оригинал":p.screenshot?"Скриншот":
                p.whatsapp?"WhatsApp":p.tech?"Техника":"Фото");
            h.label.setText(p.name);
            convert.setBackground(bg(selected.contains(p.id)?Color.rgb(206,226,255):Color.WHITE));
            h.image.setTag(p.id);h.image.setImageDrawable(null);
            Bitmap bmp=cache.get(p.id);
            if(bmp!=null)h.image.setImageBitmap(bmp);
            else {
                ImageView view=h.image;
                thumbs.execute(()->{
                    try{
                        Bitmap thumb=getContentResolver().loadThumbnail(p.uri,new Size(180,180),null);
                        if(thumb==null)return;
                        cache.put(p.id,thumb);
                        ui.post(()->{
                            Object tag=view.getTag();
                            if(tag instanceof Long && ((Long)tag).longValue()==p.id)
                                view.setImageBitmap(thumb);
                        });
                    }catch(Exception ignored){}
                });
            }
            convert.setOnClickListener(v->{if(!selected.add(p.id))selected.remove(p.id);updateCount();notifyDataSetChanged();});
            return convert;
        }
    }
    static class Holder {
        final ImageView image;
        final TextView marker,label;
        Holder(ImageView i,TextView m,TextView l){image=i;marker=m;label=l;}
    }
}
