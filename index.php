<?php
    require_once 'db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <div>
        <div>
            <H3>Sidor skapade</H3>
            <Form>
                <input id="TitleForSite" Placeholder="Title for site">
                <input id="ContentForSite" Placeholder="Content for site">
                <input id="NameOfImg" Placeholder="Name of Img">
                <input id="AltTxtForImg" Placeholder="Alt text for img">
            </Form>
            <Button id="AddSideBtn">Add side</Button> 
        </div>
        <div>
            <select name="sometext">
                <Option> <Button id="EditSideBtn">Edit side</Button></Option>
            </select>
        </div>
        <Button id="RemoveBtn">Remove Site</Button>
    </div>
</body>
</html>